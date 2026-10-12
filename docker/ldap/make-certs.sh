#!/bin/sh
# DEVELOPMENT ONLY: self-signed CA + server certificate for the `ldap`
# container, written once into the shared ldap-certs volume. The app trusts
# exactly this CA (LDAP_CA_CERT), so certificate validation stays on.
set -e
cd /certs
[ -f ldap.crt ] && exit 0
apk add --no-cache openssl >/dev/null
openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=Workflow Dev CA" \
    -keyout ca.key -out ca.crt
openssl genrsa -traditional -out ldap.key 2048 2>/dev/null
openssl req -new -key ldap.key -subj "/CN=ldap" -out ldap.csr
printf "subjectAltName=DNS:ldap,DNS:localhost\nextendedKeyUsage=serverAuth\n" > ext.cnf
openssl x509 -req -in ldap.csr -CA ca.crt -CAkey ca.key -CAcreateserial -days 3650 \
    -extfile ext.cnf -out ldap.crt
openssl dhparam -out dhparam.pem 2048 2>/dev/null
rm -f ldap.csr ext.cnf ca.srl ca.key
chmod 644 ca.crt ldap.crt dhparam.pem
chmod 640 ldap.key
chown 911:911 ldap.key  # openldap user inside osixia/openldap
