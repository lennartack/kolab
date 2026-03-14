FROM apheleia/almalinux9

# ENV LANG=en_US.utf8
# ENV LC_ALL=en_US.utf8
# ENV APP_DOMAIN
# ENV LMTP_DESTINATION

RUN dnf -y install \
    amavis \
    perl-DBD-MySQL \
    spamassassin \
    clamav \
    clamav-update && \
    dnf clean all

WORKDIR /root/

COPY /rootfs /

RUN mkdir -p /var/run/amavisd /var/dkim && \
    chmod -R 777 /var/run/amavisd /var/dkim && \
    mkdir -p /var/spool/amavisd && \
    chmod 777 /var/spool/amavisd

VOLUME [ "/var/spool/amavisd" ]
VOLUME [ "/var/lib/spamassassin" ]
VOLUME [ "/var/lib/clamav" ]
VOLUME [ "/var/dkim" ]

ENV POSTFIX_HOST=postfix
ENV DKIM_IDENTIFIER=dkim20240318
ENV CLAMD=false
ENV SPAM_DETECTED_LEVEL=6.2
ENV SPAM_KILL_LEVEL=6.9
# Set to D_PASS to forward spam
ENV SPAM_KILL_ACTION=D_DISCARD
ENV VIRUS_KILL_ACTION=D_DISCARD
# ENV DB_HOST=
# ENV DB_DATABASE=
# ENV DB_USERNAME=
# ENV DB_PASSWORD=
# ENV REDIS_HOST=
# ENV REDIS_PASSWORD=
# ENV SA_EXTRA_CONFIG=

CMD ["/init.sh"]

EXPOSE 13024/tcp
