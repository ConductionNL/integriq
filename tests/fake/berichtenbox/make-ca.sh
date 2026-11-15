#!/bin/sh
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# A throwaway CA for the Berichtenbox fake. Never a PKIoverheid certificate.
# Usage: make-ca.sh <out-dir> [client-oin] [server-names]
set -eu
OUT=${1:?out dir}
OIN=${2:-00000001234567890000}
NAMES=${3:-DNS:localhost,DNS:fake,IP:127.0.0.1}
mkdir -p "$OUT"
cd "$OUT"
openssl req -x509 -newkey rsa:2048 -nodes -keyout ca.key -out ca.pem -days 30 -subj "/CN=Berichtenbox fake test CA" 2>/dev/null
openssl req -newkey rsa:2048 -nodes -keyout server.key -out server.csr -subj "/CN=fake" 2>/dev/null
printf "subjectAltName=%s\nextendedKeyUsage=serverAuth\n" "$NAMES" > server.ext
openssl x509 -req -in server.csr -CA ca.pem -CAkey ca.key -CAcreateserial -out server.pem -days 30 -extfile server.ext 2>/dev/null
openssl req -newkey rsa:2048 -nodes -keyout client.key -out client.csr -subj "/CN=integriq test sender/serialNumber=$OIN" 2>/dev/null
printf "extendedKeyUsage=clientAuth\n" > client.ext
openssl x509 -req -in client.csr -CA ca.pem -CAkey ca.key -CAcreateserial -out client.pem -days 30 -extfile client.ext 2>/dev/null
openssl req -x509 -newkey rsa:2048 -nodes -keyout stranger.key -out stranger.pem -days 30 -subj "/CN=not from the test CA/serialNumber=$OIN" 2>/dev/null
rm -f server.csr client.csr server.ext client.ext ca.srl
echo "$OUT: ca.pem server.pem client.pem stranger.pem (client OIN $OIN)"
