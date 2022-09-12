Advanced Search adapter for Solr (module for Omeka S)
=====================================================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Advanced Search adapter for Solr] is a module for [Omeka S] that provides an
[Advanced Search] adapter for [Apache Solr], so it is possible to get the power
of a full search engine inside Omeka, for the public or in admin: search by
relevance (score), instant search, facets, autocompletion, suggestions, etc.

Technically, the module is based on the library [Solarium], and it is compatible
with any past, current and future versions of Solr. It is well maintained, and
it comes with a full api and a [full documentation], so it allows to integrate
new features simpler, in particular for the indexation and the querying. This
library is used in the equivalent modules for most common cms too.

The main advantage of this library is that it **_doesn’t_** require the
[Solr PHP extension] installed on the server, so it can be installed simpler as
any other module, in particular on any shared web hosting services. Of course,
it still requires a Solr server, but it can be provided by another server or by
a third party.


Installation
------------

This module uses the module [Advanced Search] that should be installed first
(version 3.3.6 or above).

The optional module [Generic] may be installed first.

The module uses an external library, [Solarium], so use the release zip to
install it, or use and init the source.

See general end user documentation for [installing a module].

* From the zip

Download the last release [SearchSolr.zip] from the list of releases (the master
does not contain the dependency), and uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `SearchSolr`, go to the root of the module, and run:

```sh
composer install --no-dev
```

### Requirements

- Module [Advanced Search] version 3.3.6 or above.
- A running Apache Solr. Compatibility:
  - version 3.5.15 of this module has been tested with Solr 5 and Solr 6.
  - version 3.5.15.2 of this module has been tested with Solr 6 to Solr 8.
  - version 3.5.32.3 of this module has been tested with Solr 8 and above.

Quick start
-----------

1. Installation
    1. Install Solr (see a Solr tutorial or documentation, or [below for Debian]).
    2. Create a Solr index (= "core", "collection", or "node") (see [below "Solr management"]),
       that is named `omeka` or whatever you want (use it for the path in
       point 2.1).
    3. Install the module [Advanced Search].
    4. Install this module [Advanced Search adapter for Solr].
2. In Solr admin
    1. A default core `default` is automatically added, and it is linked to the
       default install of Solr with the path `solr/omeka`. It contains a default
       list of mappings for Dublin Core elements too.
    2. Check if this core is working, or configure it correctly (host, port, and
       path of the Solr instance): the status should be `OK`.
    3. This default core can be customized if needed, for example to force the
       queries to be a "OR" query (default) or a "AND" query (more common).
3. In Search admin
    1. Create an index
        1. Add a new index with name `Default` or whatever you want, using the
        Solr adapter and the `default` core.
        2. Launch the indexation by clicking on the "reindex" button (two arrows
        forming a circle).
    2. Create a page
        1. Add a page with name `Default` or whatever you want, a path to access
        it, for example `search` or `find`, the index that was created in the
        previous step (`Default (Solr)` here), and a form (`Basic`). Forms added
        by modules can manage an advanced input field and/or filters.
        2. In the page configuration, you can enable/disable facet and sort
        fields by drag-drop. The order of the fields will be the one that will
        be used for display. Note that some fields seem duplicated, but they
        aren’t. Some of them allow to prepare search indexes and some other
        facets or sort indexes. Some of them may be used for all uses.
        For example, you can use `dcterms_type_ss`, `dcterms_subject_ss`,
        `resource_class_s`, `item_set_dcterms_title_ss`, `dcterms_creator_ss`,
        `dcterms_date_s`, `dcterms_spatial_ss`, `dcterms_language_ss` and
        `dcterms_rights_ss` as facets, and `Relevance`, `dcterms_title_s`,
        `dcterms_date_s`, and `dcterms_creator_s` as sort fields. See below more
        information about [indexation in Solr].
        3. Edit the name of the label that will be used for facets and sort
        fields in the same page. The string will be automatically translated if
        it exists in Omeka.
        4. There are options for the default search results. If wanted, the
        query may be nothing, all, or anything. See the [documentation].
4. In admin or site settings
    1. To access to the search form, enable it in the main settings (for the
       admin board) and in the site settings (for the front-end sites). So the
       search engine will be available in the specified path: `https://example.com/s/my-site/search`
       or `https://example.com/admin/search` in this example.
    2. Optionally, add a custom navigation link to the search page in the
       navigation settings of the site.
5. In Solr dashboard
    1. In the case the search doesn’t return any results, check the config of
       the core in the Solr Dashboard and see [this issue on omeka.org] to set
       and fill the default field. Or check if there is a field "`*`" in the
       schema that is ignored.

The search form should appear. Type some text then submit the form to display
the results as grid or as list. The page can be themed.

**IMPORTANT**

The Search module does not replace the default search page neither the default
search engine. So the theme should be updated.

Don’t forget to check Search facets and sort fields of each search page each
time that the list of core fields is modified: the fields that don’t exist
anymore are removed; the new ones are not added; the renamed ones are updated,
but issues may occur in case of duplicate names.

Furthermore, a check should be done when a field has the same name for items and
item sets.

Don’t forget to reindex the fields each time the Solr config is updated too.


Indexation in Solr
------------------

The default indexation is working fine: everything is indexed as text and most
of people use the basic search engine à la Google. In such cases, Solr manages
uppercase/lowercase, transliteration, scoring, etc.

Nevertheless, some people want an advanced search where they can request on a
specific property, or a group of metadata, with pattern, and even combine them
together with various joiners (and, or, not, near…). In that particular case, it
will be required to create multiple indexes in details.

### Indexation with third party

The module makes possible to store multiple indexes in the same core. This is an
advanced feature that is useless in most of the cases. It may be used to share
one core between multiple Omeka install or with another tool or another cms. To
make it working, you have to fill the field name where the index name will be
stored in the config of the core. Of course, the fields should be defined
precisely and in coherence with the other tool that access to it.

With Drupal, the default fields to set in the core form are: `bs_is_public`,
`ss_resource_name`, `im_site_id`, and `index_id`. The mapping should be created
according to the config inside Drupal, for example: `ss_title` and `tm_body`.
The sort fields are automatically managed.


TODO
----

- [ ] Create an automatic mode from the resource templates or from Dublin Core.
- [ ] Create automatically multiple index by property (text, string, lower, latin, for query, order, facets, etc.).
- [ ] Use the search engine directly without search api.
- [ ] Check lazy loading and use serialized php as response format for [performance](https://solarium.readthedocs.io/en/stable/solarium-concepts/).
- [x] Speed up indexation (in module Search too) via direct sql? BulkExport? Queue?
- [ ] Replace class Schema and Field with solarium ones.
- [ ] Rewrite and simplify querier to better handle solarium.
- [ ] Improve management of value resources and uris, and other special types.
- [ ] Add a separate indexer for medias and pages.
- [ ] Add a redirect from item-set/browse to search page, like item-set/show.
- [ ] Remove the fix for indexation of string "0", replaced by "00".
- [ ] Include all new advanced filters mode for properties.
- [ ] Manage indexation of item sets when module Item Set Tree is used.
- [ ] Facet range: determine start/end/gap automatically or add option.


Solr install <a id="solr-install"></a>
------------

The packaged release of Solr on Debian is obsolete (3.6.2), so it should be
installed via the original sources. If you have a build or a development server,
it’s recommended to create a Solr package outside of the server and to install
it via `dpkg`. The process is the same  for Red Hat and derivatives.

### Install Solr

The module works with Solr 5.5.5 (Java [1.7 u55]) and Solr 6.6.6 (Java [1.8]),
and Solr 7.7 and 8.8 (with Java [1.8] or higher). The last stable versions of Solr
and Java (OpenJdk 11) are recommended.

```sh
cd /tmp
# Check if java is installed with the good version.
java -version
# If not installed, install it (uncomment)
#sudo apt install default-jre
# On CentOs:
#sudo dnf install java-11-openjdk-devel.x86_64
# If the certificate is obsolete on Apache server, add --no-check-certificate.
# To install another version, just change all next version numbers below.
wget https://archive.apache.org/dist/lucene/solr/8.11.1/solr-8.11.1.tgz
# Extract the install script
tar zxvf solr-8.11.1.tgz solr-8.11.1/bin/install_solr_service.sh --strip-components=2
# Launch the install script (by default, Solr is installed in /opt; check other options if needed)
sudo bash ./install_solr_service.sh solr-8.11.1.tgz
# Add a symlink to simplify management (if not automatically created).
#sudo ln -s /opt/solr-8.11.1 /opt/solr
# Clean the sources.
rm solr-8.11.1.tgz
rm install_solr_service.sh
```

If not protected by a firewall or a proxy, you will access to solr admin page at
http://example.org:8983/solr. See below to create a ssh tunnel to access it when
protected.

### Integration in the system

Solr may be managed as a system service:

```sh
sudo systemctl status solr
sudo systemctl stop solr
sudo systemctl start solr
```

The result may be more complete with direct command:

```sh
sudo su - solr -c "/opt/solr/bin/solr status"
sudo su - solr -c "/opt/solr/bin/solr stop"
sudo su - solr -c "/opt/solr/bin/solr start"
sudo su - solr -c "/opt/solr/bin/solr restart"
```

**Warning**: Solr is a java application, so it is very slow to start, stop and
restart. You may need to wait five minutes between two commands.

Solr is automatically launched and available in your browser at [http://localhost:8983].

Solr is available via command line too at `/opt/solr/bin/solr`. You may want to
add yourself to the solr group (`sudo usermod -aG solr myName`).

If the service is not available after the install, you can create the file "/etc/systemd/system/solr.service",
that may need to be adapted for the distribution, here for Debian 11 or CentOs 8 (see the [solr service gist]):

This is useless if the file "/etc/init.d/solr" is available and used. Note that
the default init doesn't manage restart on failure. The following service sets
it at 30 seconds. Furthermore, the logs are simpler with systemd.

After creating the following file, run `sudo systemctl enable solr`, then `sudo systemctl daemon-reload`.

```ini
# Save this file as /etc/systemd/system/solr.service as root

# below paths assume solr installed in /opt/solr, SOLR_PID_DIR is /data
# and that all configuration exists in /etc/default/solr.in.sh which is the case if previously installed as an init.d service
# change port in pid file if differs
#
# note that it is configured to auto restart solr if it fails (Restart=on-failure) and that's the motivation indeed :)
# to switch from systemv (init.d) to systemd, do the following after creating this file:
# sudo systemctl daemon-reload
# sudo service solr stop # if already running
# sudo systemctl enable solr
# systemctl start solr
# this was inspired by https://confluence.t5.fi/display/~stefan.roos/2015/04/01/Creating+systemd+unit+(service)+for+Apache+Solr

[Unit]
Description=Apache Solr
ConditionPathExists=/opt/solr
Wants=network-online.target
After=network-online.target
Before=multi-user.target
Conflicts=shutdown.target
StartLimitIntervalSec=60

[Service]
User=solr
Group=solr

Type=forking
ExecStart=/opt/solr/bin/solr start
ExecReload=/opt/solr/bin/solr restart
ExecStop=/opt/solr/bin/solr stop
Restart=on-failure
RestartSec=30

# Optional config.
LimitNOFILE=1048576
LimitNPROC=1048576
# PIDFile=/var/solr/solr-8983.pid
# Environment=SOLR_INCLUDE=/etc/default/solr.in.sh
# Environment=RUNAS=solr
# Environment=SOLR_INSTALL_DIR=/opt/solr

[Install]
WantedBy=multi-user.target
```

### Protect access to Solr (Solr 8 and above)

For documentation before Solr 8, see the readme of this module until version 3.5.31.3.

You may need some more commands to protect install. Check the default port 8983.

The simplest solution is to close this port with your firewall and to use Apache
as a reverse proxy to it, so only Apache should be protected.

In any case, you need to use the default user or to add a user to access to the
admin board. Search on your not-favorite search engine to add such a protection.

As indicated in [Solr Basic Authentication], add the file `/var/solr/data/security.json`,
with the user roles you want (here the user `omeka_admin` is added as `admin`).
Because the password is hashed (salt + sha256), it may be simpler to use the
example,  then to update the admin, then to remove the example user (solr, with
password "SolrRocks"):

```json
{
    "authentication":{
        "blockUnknown": true,
        "class":"solr.BasicAuthPlugin",
        "credentials":{
            "solr": "IV0EHq1OnNrj6gvRCwvFwTrZ1+z1oBbnQdiVC3otuq0= Ndd7LKvVBAaZIF0QAVi1ekCfAJXr1GGfLtRUXhgrF8c="
        },
        "realm":"Omeka Solr",
        "forwardCredentials": false
    },
    "authorization":{
        "class":"solr.RuleBasedAuthorizationPlugin",
        "permissions":[
            {
                "name":"security-edit",
                "role":"admin"
            }
        ],
        "user-role":{
            "omeka_admin":"admin",
            "solr":"admin"
        }
    }
}
```

Don't forget to change rights of this file, then to restart Solr and wait some
minutes for java:

```sh
sudo chown solr:solr /var/solr/data/security.json && sudo chmod g+r,o-rw /var/solr/data/security.json
sudo systemctl restart solr
```

To add the hashed password, it is simpler to use the api endpoint, so add the
specific admin user like that, and restart the server:

**Important**: don't forget to remove the next lines from the shell or browser
history, because they contain the password. Or add a space before the commmand
line to skip it from the history. Anyway, the password should be added in the
config of the module, so it is available in the database.

```sh
curl --user solr:SolrRocks http://localhost:8983/api/cluster/security/authentication -H 'Content-type:application/json' -d '{"set-user": {"omeka_admin":"My Secret Pass Phrase"}}'
sudo systemctl restart solr
```

Finally, after some minutes for java, you should **remove the default admin "solr"**
or change its password, and **restart the server again**:

```sh
curl --user 'omeka_admin:My Secret Pass Phrase' http://localhost:8983/api/cluster/security/authentication -H 'Content-type:application/json' -d  '{"delete-user": ["solr"]}'
sudo systemctl restart solr
```

Of course, the user `omeka_admin` and the password should be set in the config
of the core in the Solr page inside Omeka.

### Taking Solr to production

See [taking Solr to production].

```sh
sudo touch /etc/security/limits.d/200-solr.conf
sudo chmod o+w /etc/security/limits.d/200-solr.conf
sudo echo "solr    hard    nofile  65000" >> /etc/security/limits.d/200-solr.conf
sudo echo "solr    hard    nproc   65000" >> /etc/security/limits.d/200-solr.conf
sudo echo "solr    soft    nofile  65000" >> /etc/security/limits.d/200-solr.conf
sudo echo "solr    soft    nproc   65000" >> /etc/security/limits.d/200-solr.conf
sudo chmod o-w /etc/security/limits.d/200-solr.conf
sudo systemctl restart solr
```

**Important**: It is recommended to protect Solr with a reverse proxy (see below).

### Enable ssl (https) when not behind a proxy

If you don't use localhost and if your Solr server is separated from the web
server and not in a secure network or not behind a firewall or you want to
access it from outside, it is  recommended to secure it. See below to hide it
behind Apache for a simpler configuration.

The [reference guide] of Solr explains this point, but the example uses a
self-signed certificate, that may be rejected by the web server if it has not
the root certificate.

So take a true certificate or create a Let's encrypt one, then enable it in Solr.

```sh
# Check the existing certificate and backup it if needed.
ls -lsa /opt/solr/server/etc/*.p12
# Create the p12 certificate with the full chain of the certificate.
sudo openssl pkcs12 -export -out ll -lsa /opt/solr/server/etc/solr-ssl.keystore.p12 -inkey /path/to/my/private/ssl.key -in /path/to/my/ssl.cert -certfile /path/to/the/complete/ssl.full-chain.everything
# Secure the certificate.
sudo chown -R solr:solr /opt/solr/server/etc/*.p12
sudo chmod go-rwX /opt/solr/server/etc/*.p12
```

Next, enable the ssl inside the main config file "sudo nano /etc/default/solr.in.sh",
as specified in the [guide]. The port may be changed. Solr doesn't seem to
recommend 8443 anymore, but 8984. Anyway, it's only a choice. You can keep 8983
too. In all cases, don't forget to open the port in the firewall, for example
with firewall-d:

```sh
sudo firewall-cmd --permanent --add-port=8984/tcp
sudo firewall-cmd --reload
```

Finally, stop and restart Solr.

Sometime, the restart is too quick, so you may have to remove all lock files
before restart. Check status and logs if needed.

```sh
sudo systemctl stop solr
sudo find /var/solr/data -name 'write.lock' -type f -delete
sudo systemctl restart solr
```

**Important**: the certificate of solr should be updated each time the source
certificate expires.

### Managing CORS with Solr

When called from the server through the module, CORS don't need to be configured.
But when the autosuggestion is configured to use an endpoint directly from the
browser, generally for performance reasons, the server should be either behind a
reverse proxy, or configured to accept direct requests from ajax.

To add the CORS header to Solr is slightly complicate, so not described here.
Anyway, it is not recommended to expose Solr directly to the web without any
security measures.

### Use Apache as a reverse proxy for Solr

To configure a reverse proxy for Solr with Apache, create this file "/etc/apache2/sites-available/reverse_proxy.conf":

```xml
<VirtualHost *:8984>
    ServerName solr.mydomain.com
    ProxyPreserveHost on
    ProxyRequests off

    RewriteEngine On
    RewriteRule ^\/solr(.*)$ $1 [L,R]

    ProxyPass / http://localhost:8983/solr/
    ProxyPassReverse / http://localhost:8983/solr/
</VirtualHost>
```

Here, Solr should be available through port 8983 without ssl on the server, so
the firewall should be configured to close this port and to open 8984. You can
complete this virtual host with your ssl config.

Then enable this new config:
```sh
sudo a2ensite reverse_proxy
```

### Upgrade Solr

Before upgrade, **you should backup the folder `/var/solr` and check the backup**
in all cases, and in particular when the config is not the default one. For Solr
itself, with the default install mode, the new version is installed beside the
current one, so it is not required to backup the app itself, but you can backup
the folder `/opt/solr/server/etc` if you want.

Note: Solr can only be upgraded one major version by one major version, so to
upgrade from version 6 to 8, you first need to upgrade to version 7.

```sh
cd /tmp
java -version
#sudo apt install default-jre
wget https://archive.apache.org/dist/lucene/solr/8.11.1/solr-8.11.1.tgz
tar zxvf solr-8.11.1.tgz solr-8.11.1/bin/install_solr_service.sh --strip-components=2
# The "-f" means "upgrade". The symlink /opt/solr is automatically updated.
sudo bash ./install_solr_service.sh solr-8.11.1.tgz -f
rm solr-8.11.1.tgz
rm install_solr_service.sh
# See below to upgrade the indexes.
```

### Uninstall Solr

When Solr is installed manually, there is no automatic uninstallation process.
The next commands are dangerous, so check the commands above twice before
executing, in particular don’t add whitespace after the slashs "/".

```sh
sudo systemctl stop solr
sudo update-rc.d -f solr remove
# Equivalent for CentOs:
# sudo chkconfig --del solr
sudo rm /etc/init.d/solr
sudo rm /etc/rc.d/init.d/solr
sudo rm /etc/default/solr.in.sh
sudo rm /etc/security/limits.d/200-solr.conf
sudo rm -r /opt/solr
sudo rm -r /opt/solr-8.11.1
# Only if you want to remove your indexes. WARNING: this will remove your configs too.
# sudo rm -r /var/solr
sudo deluser --remove-home solr
sudo deluser --group solr
```

The config and the data located in `/var/solr/data` by default can be removed
too.


Solr management <a id="solr-management"></a>
---------------

Until Solr version 7, the common way to configure Solr was to use the command
line. Since version 8, it's often simpler to use the api endpoint, via a browser
or via curl. In fact, the command is now a shortcut to the endpoint and the url
is indicated in the results. Of course, it can be done via the ui too.

To access to the ui when the Solr is protected, you can create a tunnel via ssh:

```sh
ssh -N -f user@myserver.org -L8983:myserver.org:8983
```

Then you can go to `http://localhost:8983` with your browser, that will be
redirected to the real server.

### Create a config

At least one index ("core", "collection", or "node")  should be created in Solr
to be used with Omeka. The simpler is to create one via the command line to
avoid permissions issues.

```sh
# Via command:
sudo su - solr -c "/opt/solr/bin/solr create -c omeka -n data_driven_schema_configs"
# Via api:
curl --user 'omeka_admin:MySecretPassPhrase' 'http://localhost:8983/solr/admin/cores?action=CREATE&name=omeka&instanceDir=omeka&schema=data_driven_schema_configs'
```

Here, the user `solr` launches the command `solr` to create the core `omeka`,
and it will use the default config schema `data_driven_schema_configs`. This
schema simplifies the management of fields, because they are guessed from the
data.

Here, the path to set in the config of the core in Omeka S is `solr/omeka`.

You can check it via the web interface at [http://localhost:8983/solr/#/omeka].
You can access to the localhost through a ssh tunnel, or use the domain you set
in the config.

The config files are saved in `/var/solr/data` by default.

Possible issues (always **restart solr after trying next commands**):

- The directory /var/solr is not belonging to solr, so run `sudo chown -R solr:solr /var/solr`.
- The resources may be missing, so copy them:
  ```sh
  sudo cp -r /opt/solr/server/solr/configsets/_default/conf /opt/solr/server/resources/
  ```
- There may be remaining files after a failed creation, so run first `sudo su - solr -c "/opt/solr/bin/solr delete -c omeka"`
  or `curl --user 'omeka_admin:MySecretPassPhrase' 'http://localhost:8983/solr/admin/cores?action=UNLOAD&core=omeka&deleteIndex=true&deleteDataDir=true&deleteInstanceDir=true'`
- There may be a rights issue, so backup and remove the file "security.json"
  from the data directory, then create the core with the command above, then
  restore the file "security.json".

If nothing is working (you don't see the core inside the front-end), create the
core yourself with these commands, here with a core named `omeka`:

```sh
sudo cp -r /opt/solr/server/solr/configsets/_default /var/solr/data
# The destination directory inside data is the name of the core.
sudo mv /var/solr/data/_default /var/solr/data/omeka
sudo touch /var/solr/data/omeka/core.properties
sudo echo "#Written by CorePropertiesLocator" >> /var/solr/data/omeka/core.properties
sudo echo "#Tue Nov 08 00:00:00 UTC 2021" >> /var/solr/data/omeka/core.properties
sudo echo "name=omeka" >> /etc/security/limits.d/200-solr.conf
sudo chmod ug+rw /var/solr/data/omeka/core.properties
sudo chown -R solr:solr /var/solr
sudo systemctl restart solr
```

The file `core.properties` above should contain the name of the core, that
should be the name of the directory:

```ini
#Written by CorePropertiesLocator
#Tue Nov 08 00:00:00 UTC 2021
name=omeka
```

### Querying Solr

You can check if the Solr core is working via the user interface or via such a
command:

```sh
curl --user 'omeka_admin:MySecretPassPhrase' 'http://localhost:8983/solr/omeka/select?q=*:*&indent=on&wt=json'
```

### Fixing the issue when there is no result

When there is no default field, Solr may not answer anything. To fix this issue,
as indicated in [this issue on omeka.org], add the copy field `_text_` with source `*`.

It can be done via the user interface (in the menu Schema). Or you can use this
command, as indicated in the [reference guide to copy a field]:

```sh
curl --user 'omeka_admin:MySecretPassPhrase' -X POST --data-binary '{"add-copy-field":{"source":"*","dest":"_text_" }}' 'http://localhost:8983/solr/omeka/schema'
```

Of course, you need to reindex resources after modifying schema.

### Upgrade a config

If you choose a data driven schema, you can remove it and create a new one with
the same name.

```sh
# Warning: These commands are used for data driven indexation **without specific config**. Else, backup your config first.
sudo su - solr -c "/opt/solr/bin/solr delete -c omeka"
sudo su - solr -c "/opt/solr/bin/solr create -c omeka -n data_driven_schema_configs"
```

If you have a special config, consult the [Solr documentation].

After, an upgrade or any change in the config, go back to Omeka to reindex data.

### Clean logs of Solr

If you keep the default config of Solr, the logs are stored on the main file
system and after some months or years, the file system may be filled. So change
the config or add a cron to stop/clean/restart Solr.

```sh
sudo /opt/solr/bin/solr stop
sudo rm /opt/solr/server/logs/solr-8983-console.log
sudo /opt/solr/bin/solr start
```

### Migrate a config

If you have a new server and that the version is the same or the previous one,
it is possible to copy the old install to the new one. Don't forget to remove
the write lock if needed before restarting:

```sh
# From the new server (for a core named "omeka").
sudo systemctl stop solr
rsync -va user@oldserver.com:/var/solr/data/omeka /var/solr/data
rm /var/solr/data/omeka/data/index/write.lock
sudo chown -R solr:solr /var/solr
sudo systemctl restart solr
```


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.

Note: By default, the config of the server Solr is saved in `/opt/solr/server/etc`
and in `/etc/default/solr.in.sh`; the config of the cores are saved in `/var/solr/data`.


Troubleshooting
---------------

See online issues on the [module issues] page on GitHub.


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

This software is governed by the CeCILL license under French law and abiding by
the rules of distribution of free software. You can use, modify and/ or
redistribute the software under the terms of the CeCILL license as circulated by
CEA, CNRS and INRIA at the following URL "http://www.cecill.info".

As a counterpart to the access to the source code and rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors have only limited liability.

In this respect, the user’s attention is drawn to the risks associated with
loading, using, modifying and/or developing or reproducing the software by the
user in light of its specific status of free software, that may mean that it is
complicated to manipulate, and that also therefore means that it is reserved for
developers and experienced professionals having in-depth computer knowledge.
Users are therefore encouraged to load and test the software’s suitability as
regards their requirements in conditions enabling the security of their systems
and/or data to be ensured and, more generally, to use and operate it in the same
conditions as regards security.

The fact that you are presently reading this means that you have had knowledge
of the CeCILL license and that you accept its terms.


Copyright
---------

See commits for full list of contributors.

* Copyright BibLibre, 2016-2017 (see [BibLibre])
* Copyright Daniel Berthereau, 2017-2022 (see [Daniel-KM])
* Copyright Paul Sarrassat, 2018

This module is a full replacement of the module [Solr], a deprecated fork of the
module [Solr by BibLibre]. This later was built for the [digital library Explore]
of [Université Paris Sciences & Lettres]. The fork [Advanced Search adapter for Solr]
is built for the future [digital library Manioc] of [Université des Antilles et de la Guyane],
currently managed with [Greenstone].


[Advanced Search adapter for Solr]: https://gitlab.com/Daniel-KM/Omeka-S-module-SearchSolr
[Omeka S]: https://omeka.org/s
[Solr]: https://gitlab.com/Daniel-KM/Omeka-S-module-Solr
[Solr by BibLibre]: https://github.com/biblibre/omeka-s-module-Solr
[Advanced Search]: https://gitlab.com/Daniel-KM/Omeka-S-module-AdvancedSearch
[Search by BibLibre]: https://github.com/biblibre/omeka-s-module-search
[Apache Solr]: https://solr.apache.org/
[Solarium]: https://www.solarium-project.org/
[full documentation]: https://solarium.readthedocs.io/en/stable/
[Generic]: https://gitlab.com/Daniel-KM/Omeka-S-module-Generic
[Installing a module]: http://dev.omeka.org/docs/s/user-manual/modules/#installing-modules
[documentation]: https://solr.apache.org/guide/the-dismax-query-parser.html#q-alt-parameter
[this issue on omeka.org]: https://forum.omeka.org/t/search-field-doesnt-return-results-with-solr/11650/12
[Solr PHP extension]: https://pecl.php.net/package/solr
[below]: #manage-solr
[below for Debian]: #solr-install
[below "Solr management"]: #solr-management
[1.8]: https://solr.apache.org/docs/7_2_1/SYSTEM_REQUIREMENTS.html
[1.7 u55]: https://solr.apache.org/docs/5_5_5/SYSTEM_REQUIREMENTS.html
[http://localhost:8983]: http://localhost:8983
[http://localhost:8983/solr/#/omeka]: http://localhost:8983/solr/#/omeka
[solr service gist]: https://gist.github.com/Daniel-KM/1fb475a47340d7945fa6c47c945707d0
[Solr documentation]: https://solr.apache.org/resources.html
[Solr Basic Authentication]: https://solr.apache.org/guide/basic-authentication-plugin.html#basic-authentication-plugin
[taking Solr to production]: https://solr.apache.org/guide/taking-solr-to-production.html
[reference guide]: https://solr.apache.org/guide/enabling-ssl.html
[guide]: https://solr.apache.org/guide/enabling-ssl.html#solr-in-sh
[reference guide to copy a field]: https://solr.apache.org/guide/schema-api.html#add-a-new-copy-field-rule
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-Solr/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[digital library Explore]: https://bibnum.explore.univ-psl.eu
[Université Paris Sciences & Lettres]: https://univ-psl.eu
[digital library Manioc]: http://www.manioc.org
[Université des Antilles et de la Guyane]: http://www.univ-ag.fr
[Greenstone]: http://www.greenstone.org
[BibLibre]: https://github.com/biblibre
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
