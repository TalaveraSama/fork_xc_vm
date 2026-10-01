# Prebuilt tsdecrypt binaries

Drop a binary here named `tsdecrypt-<arch>`, for example `tsdecrypt-x86_64`,
and `install-tuner-node.sh` installs it instead of building from source,
sparing a streaming node roughly 200 MB of build toolchain.

It is only used if it runs. The script tests it with `--version` first and
falls back to compiling when that fails, so a wrong or corrupt binary costs a
warning rather than a broken node.

## Why a binary from one Ubuntu will not run on another

tsdecrypt links OpenSSL dynamically — `tsdecrypt_LIBS = -lcrypto -lpthread`
in its Makefile. Ubuntu 20.04 ships `libcrypto.so.1.1`; 22.04 and 24.04 ship
`libcrypto.so.3`. A binary built on 22.04 cannot load on 20.04, and the
reverse fails too. Copying the one from a working machine therefore covers
only that release.

## Building one that travels

Link libcrypto statically, leave the rest dynamic, and build on the **oldest**
release you intend to support: glibc is forward compatible, so a binary built
on 20.04 runs on 22.04 and 24.04, never the other way round.

```sh
cd Modules/dvb_9a2c7/vendor/tsdecrypt
make clean
make ffdecsa tsdecrypt_LIBS="-l:libcrypto.a -lpthread -ldl"

ldd ./tsdecrypt          # libcrypto must NOT be listed
./tsdecrypt --version
install -m 0755 ./tsdecrypt ../prebuilt/tsdecrypt-$(uname -m)
```

`make ffdecsa` rather than plain `make` is deliberate: FFdecsa ships inside
tsdecrypt, so libdvbcsa is never needed.

## Should a binary live in git?

A statically linked tsdecrypt is a few megabytes, so it does not carry the
objection that kept the panel's 677 MB `bin/` out of the tree. It is still a
binary in a source tree, which is a provenance question as much as a size
one: whoever commits one should be able to name the source commit it came
from. That is recorded in `../PROVENANCE.txt`.
