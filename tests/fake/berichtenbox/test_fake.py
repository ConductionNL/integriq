#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
"""The fake's own tests: it must judge letters the way the official schema does.

Run: python3 tests/fake/berichtenbox/test_fake.py  (needs lxml and the openssl CLI)
"""

import base64
import json
import os
import socket
import ssl
import subprocess
import sys
import tempfile
import time
import unittest
import urllib.request
import uuid

HERE = os.path.dirname(os.path.abspath(__file__))
OIN = '00000001234567890000'


def letter(bsn='999993653', soort='Burger', bericht_type='BESLUIT', subject='Uw besluit'):
    batch = str(uuid.uuid4())
    return (
        '<?xml version="1.0" encoding="UTF-8"?>'
        '<r:Berichten xmlns:r="http://schemas.rdw.nl/GEB/BerichtVerwerkService/Types/2009/01" '
        'xmlns:b="http://schemas.rdw.nl/GEB/BerichtenProsessor/Bericht/Types/2009/01">'
        '<r:BatchInformatie><r:BatchID>%s</r:BatchID><r:AanmaakDatum>2026-10-07T20:00:00Z</r:AanmaakDatum>'
        '<r:BerichtLeverancierID>%s</r:BerichtLeverancierID></r:BatchInformatie>'
        '<b:Bericht><b:BerichtInformatie><b:BatchID>%s</b:BatchID><b:BerichtID>%s</b:BerichtID>'
        '<b:BerichtType>%s</b:BerichtType><b:Onderwerp>%s</b:Onderwerp><b:BerichtTekst>Beste burger.</b:BerichtTekst>'
        '<b:GebruikerID>%s</b:GebruikerID><b:SoortGebruiker>%s</b:SoortGebruiker></b:BerichtInformatie></b:Bericht>'
        '</r:Berichten>'
    ) % (batch, OIN, batch, str(uuid.uuid4()), bericht_type, subject, bsn, soort)


class FakeTest(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.mkdtemp(prefix='bbx-fake-')
        subprocess.run(['sh', os.path.join(HERE, 'make-ca.sh'), cls.tmp, OIN], check=True, capture_output=True)
        ready = os.path.join(cls.tmp, 'ready')
        cls.proc = subprocess.Popen([
            sys.executable, os.path.join(HERE, 'fake.py'), '--host', '127.0.0.1', '--adapter-port', '0', '--wus-port', '0',
            '--server-cert', os.path.join(cls.tmp, 'server.pem'), '--server-key', os.path.join(cls.tmp, 'server.key'),
            '--client-ca', os.path.join(cls.tmp, 'ca.pem'), '--client-oin', OIN, '--ready-file', ready,
        ])
        for _ in range(100):
            if os.path.exists(ready) and os.path.getsize(ready) > 0:
                break
            time.sleep(0.05)
        with open(ready, encoding='utf-8') as handle:
            cls.adapter_port, cls.wus_port = map(int, handle.read().split())

    @classmethod
    def tearDownClass(cls):
        cls.proc.terminate()
        cls.proc.wait(5)

    def adapter(self, method, path, body=None):
        data = None if body is None else json.dumps(body).encode()
        request = urllib.request.Request('http://127.0.0.1:%d/rest/v19/ebms/%s' % (self.adapter_port, path), data=data, method=method,
                                         headers={'Content-Type': 'application/json'})
        with urllib.request.urlopen(request) as response:
            raw = response.read()
            return json.loads(raw) if response.headers.get_content_type() == 'application/json' and raw else raw.decode()

    def send(self, xml):
        return self.adapter('POST', 'messages', {
            'properties': {'cpaId': 'fake-cpa-berichtenbox', 'fromPartyId': OIN, 'toPartyId': '00000004003214345001',
                           'service': 'urn:osb:services:GLOBE-R', 'action': 'GLOBE-R-BV-Request', 'conversationId': str(uuid.uuid4())},
            'dataSources': [{'name': 'GLOBE-R-BV-Request', 'contentType': 'application/xml', 'content': base64.b64encode(xml.encode()).decode()}],
        })

    def result_for(self, message_id):
        for result_id in self.adapter('GET', 'messages/unprocessed?action=GLOBE-R-BV-Result'):
            message = self.adapter('GET', 'messages/' + result_id)
            if message['properties']['refToMessageId'] == message_id:
                return base64.b64decode(message['dataSources'][0]['content']).decode()
        return None

    def test_a_valid_letter_is_processed(self):
        message_id = self.send(letter())
        self.assertIn('<VerwerkingsCode>Verwerkt</VerwerkingsCode>', self.result_for(message_id))
        events = self.adapter('GET', 'events/unprocessed?eventTypes=DELIVERED')
        self.assertIn(message_id, [e['messageId'] for e in events])

    def test_a_malformed_letter_is_rejected_as_logius_would(self):
        message_id = self.send(letter(soort='Bedrijf'))
        self.assertIn('XmlValidatieTegenXsdValtNegatiefUit', self.result_for(message_id))

    def test_a_subject_over_fifty_characters_is_rejected(self):
        message_id = self.send(letter(subject='x' * 51))
        self.assertIn('XmlValidatieTegenXsdValtNegatiefUit', self.result_for(message_id))

    def test_a_silent_bsn_gets_no_result(self):
        message_id = self.send(letter(bsn='999990032'))
        self.assertIsNone(self.result_for(message_id))

    def wus(self, cert, keys):
        body = (
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
            '<ValidateAbonnementen xmlns="http://schemas.rdw.nl/GEB/BerichtenboxValidatieService/2009/01">'
            '<validateAbonnementenAanvraag xmlns:a="http://schemas.rdw.nl/GEB/BerichtenboxValidatieService/Types/2009/01" '
            'xmlns:k="http://schemas.rdw.nl/GEB/Shared/Types/2009/01">'
            '<a:berichtleverancierCode>%s</a:berichtleverancierCode><a:berichtTypeCode>BESLUIT</a:berichtTypeCode>'
            '<a:klanten>%s</a:klanten></validateAbonnementenAanvraag></ValidateAbonnementen></s:Body></s:Envelope>'
        ) % (OIN, ''.join('<k:Klant><k:Key>%s</k:Key><k:Rol>Burger</k:Rol></k:Klant>' % k for k in keys))
        context = ssl.create_default_context(cafile=os.path.join(self.tmp, 'ca.pem'))
        if cert:
            context.load_cert_chain(os.path.join(self.tmp, cert + '.pem'), os.path.join(self.tmp, cert + '.key'))
        request = urllib.request.Request('https://localhost:%d/BerichtenboxValidatieService' % self.wus_port, data=body.encode(), method='POST', headers={
            'Content-Type': 'text/xml; charset=utf-8',
            'SOAPAction': '"http://schemas.rdw.nl/GEB/BerichtenboxValidatieService/2009/01/IBerichtenboxValidatieService/ValidateAbonnementen"',
        })
        try:
            with urllib.request.urlopen(request, context=context) as response:
                return response.status, response.read().decode()
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode()

    def test_wus_answers_per_bsn(self):
        status, body = self.wus('client', ['999993653', '999990019'])
        self.assertEqual(200, status)
        self.assertIn('<b:Key>999993653</b:Key><b:Rol>Burger</b:Rol></a:klant><a:isBerichtSturen>true', body)
        self.assertIn('<b:Key>999990019</b:Key><b:Rol>Burger</b:Rol></a:klant><a:isBerichtSturen>false', body)

    def test_wus_without_a_client_certificate_is_refused(self):
        with self.assertRaises((ssl.SSLError, urllib.error.URLError, ConnectionResetError, socket.error)):
            self.wus(None, ['999993653'])

    def test_wus_with_a_certificate_from_another_ca_is_refused(self):
        with self.assertRaises((ssl.SSLError, urllib.error.URLError, ConnectionResetError, socket.error)):
            self.wus('stranger', ['999993653'])

    def test_wus_fault_bsn_answers_a_fault(self):
        status, body = self.wus('client', ['999990056'])
        self.assertEqual(500, status)
        self.assertIn('DependentServiceFault', body)


if __name__ == '__main__':
    unittest.main()
