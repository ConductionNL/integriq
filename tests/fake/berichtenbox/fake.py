#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
"""A contract-faithful stand-in for Logius' Berichtenbox and a Digikoppeling ebMS adapter.

Two faces, both built from the official files vendored in lib/Adapters/Berichtenbox/Logius:

* The ebMS adapter face (plain HTTP). The subset of ebms-core's EbMSRestController
  (branch ebms-core-2.20.x) that integriq uses: POST messages, GET messages/unprocessed,
  GET messages/{id}, PATCH messages/{id}, GET events/unprocessed, PATCH events/{id}.
  Every GLOBE-R-BV-Request it receives is validated against the vendored
  GLOBEBatchRequest.xsd. An invalid letter gets the code Logius would give,
  XmlValidatieTegenXsdValtNegatiefUit. Every result it answers is validated against
  the vendored GLOBEBatchResponse.xsd before it is handed out.

* The WUS face (TLS, client certificate required). ValidateAbonnementen as the vendored
  WSDL describes it. The request body is validated against xsd0.xsd. The client
  certificate's serialNumber must carry the OIN in berichtleverancierCode.

This is a fake. Every proof run against it says so. It cannot prove what only Logius
can (design.md section 10).

Behaviour by test BSN:
  999990019  not subscribed (WUS false; a letter anyway gets NietActiefOfGeabonneerd)
  999990020  BerichtTypeNietOndersteund
  999990032  no result, ever
  999990044  transport EXPIRED, no result
  999990056  WUS ApplicationFault
  anything else valid: subscribed, Verwerkt

A file named by --fail-file makes POST messages answer 503.
"""

import argparse
import base64
import datetime
import gzip
import json
import os
import re
import ssl
import threading
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

from lxml import etree

NS_REQ = 'http://schemas.rdw.nl/GEB/BerichtVerwerkService/Types/2009/01'
NS_BERICHT = 'http://schemas.rdw.nl/GEB/BerichtenProsessor/Bericht/Types/2009/01'
NS_RESULT = 'http://schemas.rdw.nl/GEB/BerichtVerwerkService/BerichtResultaat/Types/2009/01'
NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/'
NS_WUS = 'http://schemas.rdw.nl/GEB/BerichtenboxValidatieService/2009/01'
NS_WUS_TYPES = 'http://schemas.rdw.nl/GEB/BerichtenboxValidatieService/Types/2009/01'
NS_SHARED = 'http://schemas.rdw.nl/GEB/Shared/Types/2009/01'
WUS_ACTION = 'http://schemas.rdw.nl/GEB/BerichtenboxValidatieService/2009/01/IBerichtenboxValidatieService/ValidateAbonnementen'

NOT_SUBSCRIBED = {'999990019'}
TYPE_NOT_SUPPORTED = {'999990020'}
NO_RESULT = {'999990032'}
EXPIRED = {'999990044'}
WUS_FAULT = {'999990056'}

MAX_ATTACHMENTS_BYTES = 500 * 1024


class RdwResolver(etree.Resolver):
    """Maps the internal RDW schema URLs in xsd0/xsd2 to the vendored files."""

    def __init__(self, contract_dir):
        super().__init__()
        self.dir = os.path.join(contract_dir, 'BerichtenboxValidatieService')

    def resolve(self, url, pubid, context):
        match = re.search(r'\?xsd=(xsd\d)$', url or '')
        if match:
            return self.resolve_filename(os.path.join(self.dir, match.group(1) + '.xsd'), context)
        return None


class State:
    """Everything the fake has seen and owes."""

    def __init__(self, args):
        self.args = args
        self.lock = threading.Lock()
        self.requests = []
        self.messages = {}   # inbound (from Logius) messages by id
        self.unprocessed_messages = []
        self.events = []     # {'messageId', 'type'}
        contract = args.contract_dir
        self.request_schema = etree.XMLSchema(etree.parse(os.path.join(contract, 'BerichtVerwerkService/Request/GLOBEBatchRequest.xsd')))
        self.response_schema = etree.XMLSchema(etree.parse(os.path.join(contract, 'BerichtVerwerkService/Response/GLOBEBatchResponse.xsd')))
        parser = etree.XMLParser()
        parser.resolvers.add(RdwResolver(contract))
        self.wus_schema = etree.XMLSchema(etree.parse(os.path.join(contract, 'BerichtenboxValidatieService/xsd0.xsd'), parser))

    def record(self, entry):
        with self.lock:
            self.requests.append(entry)
            if self.args.record_file:
                with open(self.args.record_file, 'a', encoding='utf-8') as handle:
                    handle.write(json.dumps(entry) + '\n')


def now_zulu():
    return datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def text_of(element, path, namespaces):
    found = element.find(path, namespaces)
    return found.text if found is not None and found.text is not None else ''


def build_result(state, oin, batch_id, letters):
    """A GLOBE-R-BV-Result for one batch, validated against the vendored response XSD."""
    counts = {'ok': 0, 'inactive': 0, 'technical': 0, 'type': 0, 'publication': 0, 'creation': 0}
    root = etree.Element('{%s}BerichtVerwerkResponse' % NS_REQ, nsmap={'ns0': NS_REQ, 'ns1': NS_RESULT})
    info = etree.SubElement(root, 'BatchInformatie')
    for code_name, value in (('BerichtLeverancierCode', oin), ('BatchID', batch_id)):
        etree.SubElement(info, code_name).text = value
    berichten = etree.Element('Berichten')
    for letter in letters:
        code = letter['code']
        if code == 'Verwerkt':
            counts['ok'] += 1
        elif code == 'NietActiefOfGeabonneerd':
            counts['inactive'] += 1
        elif code == 'BerichtTypeNietOndersteund':
            counts['type'] += 1
        else:
            counts['technical'] += 1
        bericht = etree.SubElement(berichten, '{%s}Bericht' % NS_RESULT)
        etree.SubElement(bericht, 'BerichtID').text = letter['berichtId']
        etree.SubElement(bericht, 'BerichtType').text = letter['berichtType']
        etree.SubElement(bericht, 'VerwerkingsCode').text = code
        etree.SubElement(bericht, 'Stadium').text = letter.get('stadium', 'NA')
    for code_name, value in (
        ('TotaalAantalOntvangenBerichten', len(letters)),
        ('AantalBerichtenSuccesvolVerwerkt', counts['ok']),
        ('AantalBerichtenGeenActieveBoxOfGeabonneertOpLeverancier', counts['inactive']),
        ('AantalBerichtenMetTechnischProbleem', counts['technical']),
        ('AantalBerichtenBerichtTypeNietCorrect', counts['type']),
        ('AantalBerichtenPublicatieDatumNietCorrect', counts['publication']),
        ('AantalBerichtenAanmaakDatumNietCorrect', counts['creation']),
    ):
        etree.SubElement(info, code_name).text = str(value)
    etree.SubElement(info, 'DatumOntvangen').text = now_zulu()
    etree.SubElement(info, 'DatumVerwerkt').text = now_zulu()
    root.append(berichten)
    if not state.response_schema.validate(root):
        raise RuntimeError('the fake built a result its own response XSD rejects: %s' % state.response_schema.error_log)
    return etree.tostring(root, xml_declaration=True, encoding='UTF-8')


def judge_batch(state, xml_bytes):
    """Decide each letter's outcome, the way S1 sections 5.3 to 5.6 describe it."""
    try:
        document = etree.fromstring(xml_bytes)
    except etree.XMLSyntaxError as error:
        return None, None, [], 'not XML: %s' % error
    valid = state.request_schema.validate(document)
    errors = '' if valid else str(state.request_schema.error_log)
    ns = {'r': NS_REQ, 'b': NS_BERICHT}
    oin = text_of(document, 'r:BatchInformatie/r:BerichtLeverancierID', ns)
    batch_id = text_of(document, 'r:BatchInformatie/r:BatchID', ns)
    letters = []
    for bericht in document.findall('b:Bericht', ns):
        info = bericht.find('b:BerichtInformatie', ns)
        bericht_id = text_of(info, 'b:BerichtID', ns) if info is not None else ''
        bericht_type = text_of(info, 'b:BerichtType', ns) if info is not None else ''
        bsn = text_of(info, 'b:GebruikerID', ns) if info is not None else ''
        size = 0
        for content in bericht.findall('b:Bijlagen/b:Bijlage/b:Inhoud', ns):
            try:
                size += len(base64.b64decode(content.text or '', validate=True))
            except ValueError:
                size += MAX_ATTACHMENTS_BYTES + 1
        letter = {'berichtId': bericht_id or str(uuid.uuid4()), 'berichtType': bericht_type or 'onbekend', 'bsn': bsn}
        if not valid:
            letter['code'], letter['stadium'] = 'XmlValidatieTegenXsdValtNegatiefUit', 'ValidatieBerichtType'
        elif oin != state.args.client_oin:
            letter['code'], letter['stadium'] = 'OinInCPAKomtNietOvereenMetOinInBericht', 'ValidatieGebruiker'
        elif size > MAX_ATTACHMENTS_BYTES:
            letter['code'], letter['stadium'] = 'BijlageTeGroot', 'StoreMessage'
        elif bericht_type not in state.args.bericht_types or bsn in TYPE_NOT_SUPPORTED:
            letter['code'], letter['stadium'] = 'BerichtTypeNietOndersteund', 'ValidatieBerichtType'
        elif bsn in NOT_SUBSCRIBED:
            letter['code'], letter['stadium'] = 'NietActiefOfGeabonneerd', 'ValidatieGebruiker'
        else:
            letter['code'], letter['stadium'] = 'Verwerkt', 'NA'
        letters.append(letter)
    return oin, batch_id, letters, errors


class AdapterHandler(BaseHTTPRequestHandler):
    """The ebms-core REST subset."""

    state = None

    def log_message(self, *args):
        return

    def reply(self, status, body=b'', content_type='application/json'):
        if isinstance(body, (dict, list)):
            body = json.dumps(body).encode()
        elif isinstance(body, str):
            body = body.encode()
        self.send_response(status)
        self.send_header('Content-Type', content_type)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def route(self):
        parsed = urlparse(self.path)
        match = re.search(r'/ebms/(.*)$', parsed.path)
        return (match.group(1) if match else None), parse_qs(parsed.query), parsed.path

    def authorised(self):
        token = self.state.args.adapter_token
        if not token:
            return True
        return self.headers.get('Authorization', '') == 'Bearer ' + token

    def do_GET(self):
        tail, query, path = self.route()
        if path == '/fake/requests':
            return self.reply(200, self.state.requests)
        if tail is None:
            return self.reply(404, {'error': 'no such path'})
        if not self.authorised():
            return self.reply(401, {'error': 'unauthorised'})
        self.state.record({'face': 'adapter', 'method': 'GET', 'path': tail, 'query': query})
        if tail == 'messages/unprocessed':
            action = (query.get('action') or [''])[0]
            ids = [i for i in self.state.unprocessed_messages if not action or self.state.messages[i]['properties']['action'] == action]
            return self.reply(200, ids)
        if tail == 'events/unprocessed':
            wanted = set(query.get('eventTypes') or [])
            return self.reply(200, [e for e in self.state.events if not e.get('processed') and (not wanted or e['type'] in wanted)])
        match = re.fullmatch(r'messages/([^/]+)', tail)
        if match and match.group(1) in self.state.messages:
            return self.reply(200, self.state.messages[match.group(1)])
        return self.reply(404, {'error': 'not found'})

    def do_PATCH(self):
        tail, _, _ = self.route()
        if tail is None or not self.authorised():
            return self.reply(401 if tail else 404, {'error': 'refused'})
        self.state.record({'face': 'adapter', 'method': 'PATCH', 'path': tail})
        match = re.fullmatch(r'(messages|events)/([^/]+)', tail)
        if not match:
            return self.reply(404, {'error': 'not found'})
        kind, ident = match.groups()
        with self.state.lock:
            if kind == 'messages' and ident in self.state.unprocessed_messages:
                self.state.unprocessed_messages.remove(ident)
                return self.reply(204)
            for event in self.state.events:
                if kind == 'events' and event['messageId'] == ident and not event.get('processed'):
                    event['processed'] = True
                    return self.reply(204)
        return self.reply(404, {'error': 'not found'})

    def do_POST(self):
        tail, _, path = self.route()
        length = int(self.headers.get('Content-Length') or 0)
        raw = self.rfile.read(length)
        if path == '/fake/reset':
            with self.state.lock:
                self.state.requests.clear(); self.state.messages.clear(); self.state.unprocessed_messages.clear(); self.state.events.clear()
            return self.reply(204)
        if tail != 'messages':
            return self.reply(404, {'error': 'not found'})
        if not self.authorised():
            return self.reply(401, {'error': 'unauthorised'})
        if self.state.args.fail_file and os.path.exists(self.state.args.fail_file):
            self.state.record({'face': 'adapter', 'method': 'POST', 'path': tail, 'failMode': True})
            return self.reply(503, 'The ebMS adapter is temporarily unavailable (fake fail mode)', 'text/plain')
        try:
            request = json.loads(raw)
            props = request['properties']
            for key in ('cpaId', 'fromPartyId', 'toPartyId', 'service', 'action', 'conversationId'):
                if not props.get(key):
                    raise KeyError(key)
            payload = base64.b64decode(request['dataSources'][0]['content'])
        except (ValueError, KeyError, IndexError, TypeError) as error:
            return self.reply(400, 'Bad MessageRequest: %s' % error, 'text/plain')
        args = self.state.args
        if props['cpaId'] != args.cpa_id or props['toPartyId'] != args.logius_party or props['fromPartyId'] != args.client_oin:
            return self.reply(400, 'No CPA %s between %s and %s' % (props['cpaId'], props['fromPartyId'], props['toPartyId']), 'text/plain')
        if props['action'] != 'GLOBE-R-BV-Request' or props['service'] != args.service:
            return self.reply(400, 'Action %s is not in CPA %s' % (props['action'], props['cpaId']), 'text/plain')
        message_id = props.get('messageId') or ('%s@fake-ebms' % uuid.uuid4())
        oin, batch_id, letters, errors = judge_batch(self.state, payload)
        self.state.record({
            'face': 'adapter', 'method': 'POST', 'path': tail, 'messageId': message_id, 'properties': props,
            'payload': payload.decode('utf-8', 'replace'), 'xsdValid': errors == '', 'xsdErrors': errors,
            'outcomes': [{'berichtId': l['berichtId'], 'code': l['code']} for l in letters],
        })
        bsns = {l['bsn'] for l in letters}
        with self.state.lock:
            if bsns & EXPIRED:
                self.state.events.append({'messageId': message_id, 'type': 'EXPIRED'})
                return self.reply(200, message_id, 'text/plain')
            self.state.events.append({'messageId': message_id, 'type': 'DELIVERED'})
            answered = [l for l in letters if l['bsn'] not in NO_RESULT]
            if answered:
                result = build_result(self.state, oin or args.client_oin, batch_id or str(uuid.uuid4()), answered)
                if args.gzip_results:
                    result = gzip.compress(result)
                result_id = '%s@fake-logius' % uuid.uuid4()
                self.state.messages[result_id] = {
                    'properties': {
                        'cpaId': props['cpaId'], 'fromParty': {'partyId': args.logius_party, 'role': 'Berichtenbox'},
                        'toParty': {'partyId': props['fromPartyId'], 'role': 'Berichtleverancier'},
                        'service': props['service'], 'action': 'GLOBE-R-BV-Result', 'timestamp': now_zulu(),
                        'conversationId': props['conversationId'], 'messageId': result_id, 'refToMessageId': message_id,
                        'messageStatus': 'RECEIVED',
                    },
                    'dataSources': [{'name': 'GLOBE-R-BV-Result', 'contentId': 'result', 'contentType': 'application/xml',
                                     'content': base64.b64encode(result).decode()}],
                }
                self.state.unprocessed_messages.append(result_id)
        return self.reply(200, message_id, 'text/plain')


class WusHandler(BaseHTTPRequestHandler):
    """ValidateAbonnementen, SOAP 1.1, behind a client certificate."""

    state = None

    def log_message(self, *args):
        return

    def fault(self, code, kind, text):
        body = (
            '<s:Envelope xmlns:s="%s"><s:Body><s:Fault><faultcode>s:%s</faultcode><faultstring>%s</faultstring>'
            '<detail><%s xmlns="%s"><Message>%s</Message></%s></detail></s:Fault></s:Body></s:Envelope>'
        ) % (NS_SOAP, code, text, kind, NS_SHARED, text, kind)
        self.answer(500, body)

    def answer(self, status, body):
        data = body.encode()
        self.send_response(status)
        self.send_header('Content-Type', 'text/xml; charset=utf-8')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_POST(self):
        raw = self.rfile.read(int(self.headers.get('Content-Length') or 0))
        cert = self.connection.getpeercert() or {}
        serial = ''
        for rdn in cert.get('subject', ()):
            for key, value in rdn:
                if key == 'serialNumber':
                    serial = value
        entry = {'face': 'wus', 'soapAction': self.headers.get('SOAPAction', ''), 'clientSerial': serial,
                 'payload': raw.decode('utf-8', 'replace')}
        if self.headers.get('SOAPAction', '').strip('"') != WUS_ACTION:
            entry['refused'] = 'soapAction'
            self.state.record(entry)
            return self.fault('Client', 'ApplicationFault', 'Unknown SOAPAction')
        try:
            envelope = etree.fromstring(raw)
            operation = envelope.find('{%s}Body/{%s}ValidateAbonnementen' % (NS_SOAP, NS_WUS))
            if operation is None:
                raise ValueError('no ValidateAbonnementen in the SOAP body')
        except (etree.XMLSyntaxError, ValueError) as error:
            entry['refused'] = str(error)
            self.state.record(entry)
            return self.fault('Client', 'ApplicationFault', 'Malformed request')
        if not self.state.wus_schema.validate(operation):
            entry['refused'] = str(self.state.wus_schema.error_log)
            self.state.record(entry)
            return self.fault('Client', 'ApplicationFault', 'XmlValidatieTegenXsdValtNegatiefUit')
        ns = {'t': NS_WUS_TYPES, 's': NS_SHARED, 'w': NS_WUS}
        code = text_of(operation, 'w:validateAbonnementenAanvraag/t:berichtleverancierCode', ns)
        keys = [k.text or '' for k in operation.findall('w:validateAbonnementenAanvraag/t:klanten/s:Klant/s:Key', ns)]
        entry.update({'berichtleverancierCode': code, 'keys': keys,
                      'berichtTypeCode': text_of(operation, 'w:validateAbonnementenAanvraag/t:berichtTypeCode', ns)})
        self.state.record(entry)
        if code != self.state.args.client_oin or serial != code:
            return self.fault('Client', 'ApplicationFault', 'LeverancierNietGeautoriseerd')
        if not 1 <= len(keys) <= 250:
            return self.fault('Client', 'ApplicationFault', 'Between 1 and 250 BSNs per request')
        if set(keys) & WUS_FAULT:
            return self.fault('Server', 'DependentServiceFault', 'Abonnementenregister niet bereikbaar')
        items = ''.join(
            '<a:Abonnement><a:klant xmlns:b="%s"><b:Key>%s</b:Key><b:Rol>Burger</b:Rol></a:klant>'
            '<a:isBerichtSturen>%s</a:isBerichtSturen></a:Abonnement>' % (NS_SHARED, k, 'false' if k in NOT_SUBSCRIBED else 'true')
            for k in keys
        )
        self.answer(200, (
            '<s:Envelope xmlns:s="%s"><s:Body><ValidateAbonnementenResponse xmlns="%s">'
            '<ValidateAbonnementenResult xmlns:a="%s">%s</ValidateAbonnementenResult>'
            '</ValidateAbonnementenResponse></s:Body></s:Envelope>'
        ) % (NS_SOAP, NS_WUS, NS_WUS_TYPES, items))


def main():
    here = os.path.dirname(os.path.abspath(__file__))
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument('--contract-dir', default=os.path.join(here, '../../../lib/Adapters/Berichtenbox/Logius'))
    parser.add_argument('--host', default='0.0.0.0')
    parser.add_argument('--adapter-port', type=int, default=8000)
    parser.add_argument('--wus-port', type=int, default=8443)
    parser.add_argument('--server-cert', required=True)
    parser.add_argument('--server-key', required=True)
    parser.add_argument('--client-ca', required=True)
    parser.add_argument('--client-oin', default='00000001234567890000')
    parser.add_argument('--logius-party', default='00000004003214345001')
    parser.add_argument('--cpa-id', default='fake-cpa-berichtenbox')
    parser.add_argument('--service', default='urn:osb:services:GLOBE-R')
    parser.add_argument('--bericht-types', default='BESLUIT,ZAAK')
    parser.add_argument('--adapter-token', default='')
    parser.add_argument('--fail-file', default='')
    parser.add_argument('--record-file', default='')
    parser.add_argument('--gzip-results', action='store_true')
    parser.add_argument('--ready-file', default='')
    args = parser.parse_args()
    args.bericht_types = set(filter(None, args.bericht_types.split(',')))

    state = State(args)
    AdapterHandler.state = state
    WusHandler.state = state

    adapter = ThreadingHTTPServer((args.host, args.adapter_port), AdapterHandler)
    wus = ThreadingHTTPServer((args.host, args.wus_port), WusHandler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.load_cert_chain(args.server_cert, args.server_key)
    context.load_verify_locations(args.client_ca)
    context.verify_mode = ssl.CERT_REQUIRED
    wus.socket = context.wrap_socket(wus.socket, server_side=True)

    threading.Thread(target=wus.serve_forever, daemon=True).start()
    if args.ready_file:
        with open(args.ready_file, 'w', encoding='utf-8') as handle:
            handle.write('%d %d\n' % (adapter.server_address[1], wus.server_address[1]))
    adapter.serve_forever()


if __name__ == '__main__':
    main()
