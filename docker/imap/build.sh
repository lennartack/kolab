#!/bin/bash

set -e

function checkout() {
    if [ ! -d "$1" ]; then
        git clone "$2" "$1"
        pushd "$1"
        git checkout "$3" 
        popd
    fi
}

checkout cyrus-imapd $GIT_REMOTE $GIT_REF
pushd cyrus-imapd
autoreconf -i

#-Wno-deprecated to work around the following:
#imap/http_h2.c:767:13: error: ‘MD5_Final’ is deprecated: Since OpenSSL 3.0 [-Werror=deprecated-declarations]
# imap/ctl_mboxlist.c:997:21: error: ‘free’ called on pointer ‘entry’ with nonzero offset 2052 [-Werror=free-nonheap-object]
#
# -Wno-error=type-limits for the following on ppc64le:
#imap/backend.c:839:51: error: comparison is always true due to limited range of data type [-Werror=type-limits]
#  839 |                     if ((ch = prot_getc(ret->in)) != EOF) {
#
# -Wno-error=maybe-uninitialized for the following on ppc64le:
# imap/httpd.c: In function ‘log_request’:
# imap/httpd.c:2974:9: error: ‘noargs’ may be used uninitialized [-Werror=maybe-uninitialized]
#  2974 |         comma_list_body(logbuf, upgrd_tokens, txn->flags.upgrade, 0, noargs);
# -Wno-error=format for the following on arm64:
# imap/cyr_ls.c:185:36: error: format '%lu' expects argument of type
# 'long unsigned int', but argument 12 has type '__nlink_t' {aka
# 'unsigned int'} [-Werror=format=]
./configure CFLAGS="-W -Wno-unused-parameter -g -O0 -Wall -Wextra -Werror -Wno-error=deprecated-declarations  -Wno-error=free-nonheap-object -Wno-error=type-limits -Wno-error=maybe-uninitialized -Wno-error=format -fPIC" --enable-murder --enable-http --enable-calalarmd --enable-autocreate --enable-idled --with-openssl=yes --enable-replication --prefix=/usr


make -j6
make install
popd
rm -rf cyrus-imapd

