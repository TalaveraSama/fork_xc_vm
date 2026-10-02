# Uninstalling XC_VM by hand

There is no uninstaller. This is the complete list of what `build/install`
creates, in the order it has to be undone.

**The order matters.** A tmpfs is mounted inside `/home/xc_vm`; deleting the
directory before unmounting it gives errors at best. And the root crontab may
carry the immutable attribute, which makes `crontab -r` fail in a way that
does not look like a permissions problem.

> This destroys the database, the channels, the recordings and the
> configuration. If any of it matters, do this first:
>
> ```sh
> mysqldump -u root -p xc_vm > /root/xc_vm-backup.sql
> tar czf /root/xc_vm-config.tar.gz /home/xc_vm/config /home/xc_vm/content
> ```

---

## 1. Module processes first

They hold tuners and ports, and some are detached with `setsid` so nothing
else will reap them.

```sh
pkill -f '/home/xc_vm/tmp/cache/dvb/'    # DVB module, if installed
sleep 2
pkill -9 -f '/home/xc_vm/tmp/cache/dvb/'
fuser -v /dev/dvb/adapter*/frontend0 2>&1   # should be empty
```

## 2. Stop the panel

```sh
systemctl stop xc_vm
systemctl disable xc_vm
pkill -u xc_vm
```

## 3. Crontabs — both of them

There are two: root's and `xc_vm`'s. The root one has been seen carrying the
immutable flag, in which case `crontab` fails with
`rename: Operation not permitted`, which reads like something else entirely.

```sh
lsattr /var/spool/cron/crontabs/root 2>/dev/null     # look for an 'i'
chattr -i /var/spool/cron/crontabs /var/spool/cron/crontabs/root 2>/dev/null

crontab -r -u root
crontab -r -u xc_vm

crontab -l -u root 2>/dev/null | grep XC_VM          # must be empty
```

Leaving these behind means cron keeps invoking paths that no longer exist,
once a minute, for ever.

## 4. Unmount before deleting

```sh
umount -l /home/xc_vm/content/streams 2>/dev/null
umount -l /home/xc_vm/tmp 2>/dev/null
sed -i '/xc_vm/d' /etc/fstab
grep xc_vm /etc/fstab                                 # must be empty
```

The installer adds a line like:

    tmpfs /home/xc_vm/content/streams tmpfs defaults,noatime,nosuid,nodev,noexec,mode=1777,size=90% 0 0

## 5. Service and privileges

```sh
rm -f /etc/systemd/system/xc_vm.service
rm -f /etc/sudoers.d/xc_vm
systemctl daemon-reload
```

## 6. Databases

```sh
mysql -u root -p -e "DROP DATABASE IF EXISTS xc_vm; DROP DATABASE IF EXISTS xc_vm_migrate;"
mysql -u root -p -e "DROP DATABASE IF EXISTS xc_vm_import;"   # only if tools import was used
mysql -u root -p -e \
  "SELECT DISTINCT user, host FROM mysql.db WHERE db IN ('xc_vm','xc_vm_migrate');"
```

The installer generates the MariaDB account name with
`generate_random_password(32)`, so it is a 32-character random string and
**searching for 'xc' in the username finds nothing**. Look it up by its
grants instead, as above, then `DROP USER 'thatname'@'localhost';`.

Run that query *before* dropping the databases — the grants go with them.

**Do not purge MariaDB itself** unless you are certain nothing else uses it.

## 7. Files and user

```sh
rm -rf /home/xc_vm
userdel xc_vm 2>/dev/null
groupdel xc_vm 2>/dev/null
```

## 8. Optional — what the DVB module added

```sh
rm -f /usr/local/bin/tsdecrypt
apt-get remove --purge -y dvb-tools dvblast
```

**Leave the card driver alone** if anything else on the box uses the tuner.
It is independent of the panel.

---

## Verify

```sh
systemctl status xc_vm 2>&1 | head -3
ls -d /home/xc_vm 2>&1
id xc_vm 2>&1
crontab -l 2>/dev/null | grep -c XC_VM
mount | grep xc_vm
grep -c xc_vm /etc/fstab
```

Every one should report "no such thing" or zero.

---

## Reinstalling rather than leaving

Skip step 8 entirely and keep the driver, `dvb-tools`, `dvblast` and the
compiled `tsdecrypt`. That saves rebuilding the driver and most of
`install-tuner-node.sh`. Steps 1 to 7 still apply: a half-removed install is
worse than either state, because the new one inherits a user, a database and
a crontab it did not create and does not expect.
