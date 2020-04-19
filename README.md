Solr (module for Omeka S)
=========================

[![Build Status](https://travis-ci.org/biblibre/omeka-s-module-Solr.svg?branch=master)](https://travis-ci.org/biblibre/omeka-s-module-Solr)

[Solr] is a module for [Omeka S] that provides a [Search] adapter for [Apache Solr].


Installation
------------

Uncompress the zip inside the folder `modules` and rename it `Solr`.

See general end user documentation for [Installing a module].

### Requirements

- Module [Search]
- [Solr PHP extension] (>= 2.0.0). It must be enabled for the CLI as well as the
  web server.
- A running Apache Solr 5 or 6 instance. The module works with Solr 5.5.5
  (Java [1.7 u55]) and Solr 6.6.6 (Java [1.8]), but not Solr 7.2.1 (indexing
  works, not the search).  This instance can be installed on the same server
  than Omeka, or provided by another server, a virtual machine, or a cloud
  service.


Quick start
-----------

1. Installation
    1. Install Solr (see a Solr tutorial or documentation, or [below for Debian]).
    2. Create a Solr index (= "node", "core" or "collection") (see [below "Solr management"]),
       that is named `omeka` or whatever you want (use it for the path in
       point 2.1).
    3. Install the [Search] module.
    4. Install this module.
2. In Solr admin
    1. A default node `default` is automatically added, and it is linked to the
       default install of Solr with the path `solr/omeka`. It contains a default
       list of mappings for Dublin Core elements too.
    2. Check if this node is working, or configure it correctly (host, port, and
       path of the Solr instance): the status should be `OK`.
    3. This default node can be customized if needed, for example to force the
       queries to be a "OR" query (default) or a "AND" query (more common).
3. In Search admin
    1 . Create an index
        1. Add a new index with name `Default` or whatever you want, using the
        Solr adapter and the `default` node.
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
        `dcterms_date_s`, and `dcterms_creator_s` as sort fields.
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

The search form should appear. Type some text then submit the form to display
the results as grid or as list. The page can be themed.

**IMPORTANT**

The Search module does not replace the default search page neither the default
search engine. So the theme should be updated.

Don’t forget to check Search facets and sort fields of each search page each
time that the list of node fields is modified: the fields that don’t exist
anymore are removed; the new ones are not added; the renamed ones are updated,
but issues may occur in case of duplicate names.

Furthermore, a check should be done when a field has the same name for items and
item sets.

Don’t forget to reindex the fields each time the Solr config is updated too.


Solr install <a id="solr-install"></a>
------------

The packaged release of Solr on Debian is obsolete (3.6.2), so it should be
installed via the original sources. If you have a build or a development server,
it’s recommended to create a Solr package outside of the server and to install
it via `dpkg`. The process is the same  for Red Hat and derivatives.

### Install Solr

The module works with Solr 5.5.5 (Java [1.7 u55]) and Solr 6.6.6 (Java [1.8]), but
not Solr 7.2.1 (indexing works, not the search).

```bash
cd /opt
# Check if java is installed with the good version.
java -version
# If not installed, install it (uncomment)
#sudo apt install default-jre
# The certificate is currently obsolete on Apache server, so don’t check it.
# To install another version, just change all next version numbers below.
wget --no-check-certificate https://www.eu.apache.org/dist/lucene/solr/6.6.6/solr-6.6.6.tgz
# Extract the install script
tar zxvf solr-6.6.6.tgz solr-6.6.6/bin/install_solr_service.sh --strip-components=2
# Launch the install script (by default, Solr is installed in /opt; check other options if needed)
sudo bash ./install_solr_service.sh solr-6.6.6.tgz
# Add a symlink to simplify management (if not automatically created).
#sudo ln -s /opt/solr-6.6.6 /opt/solr
# Clean the sources.sudo bash ./install_solr_service.sh solr-6.6.6.tgz
rm solr-6.6.6.tgz
rm install_solr_service.sh
```

### Integration in the system

Solr may be managed as a system service:

```bash
sudo systemctl status solr
sudo systemctl stop solr
sudo systemctl start solr
```

Solr is automatically launched and available in your browser at [http://localhost:8983].

Solr is available via command line too at `/opt/solr/bin/solr`.

If the service is not available after the install, you can create the file "/etc/systemd/system/solr.service",
that may need to be adapted for the distribution, here for Centos 8 (see the [solr service gist]):

```ini
# put this file in /etc/systemd/system/ as root
# below paths assume solr installed in /opt/solr, SOLR_PID_DIR is /data
# and that all configuration exists in /etc/default/solr.in.sh which is the case if previously installed as an init.d service
# change port in pid file if differs
# note that it is configured to auto restart solr if it fails (Restart=on-faliure) and that's the motivation indeed :)
# to switch from systemv (init.d) to systemd, do the following after creating this file:
# sudo systemctl daemon-reload
# sudo service solr stop # if already running
# sudo systemctl enable solr
# systemctl start solr
# this was inspired by https://confluence.t5.fi/display/~stefan.roos/2015/04/01/Creating+systemd+unit+(service)+for+Apache+Solr
[Unit]
Description=Apache SOLR
ConditionPathExists=/opt/solr
After=syslog.target network.target remote-fs.target nss-lookup.target systemd-journald-dev-log.socket
Before=multi-user.target
Conflicts=shutdown.target
StartLimitIntervalSec=60

[Service]
User=solr
LimitNOFILE=1048576
LimitNPROC=1048576
PIDFile=/var/solr/solr-8983.pid
Environment=SOLR_INCLUDE=/etc/default/solr.in.sh
Environment=RUNAS=solr
Environment=SOLR_INSTALL_DIR=/opt/solr

Restart=on-failure
RestartSec=5

ExecStart=/opt/solr/bin/solr start
ExecStop=/opt/solr/bin/solr stop
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

Note: In some Red Hat derivatives, the extension php-solr is not available and
you may  need to create a link. For example if you use the package "php72-php-pecl-solr2-2.5.0-1.el8.remi.x86_64"
for Centos 8, you should add these links:

```sh
sudo ln -s /etc/opt/remi/php72/php.d/50-solr.ini /etc/php.d/50-solr.ini
sudo ln -s /opt/remi/php72/root/usr/lib64/php/modules/solr.so /usr/lib64/php/modules/solr.so
# And restart services.
sudo systemctl restart php-fpm
sudo systemctl restart httpd
```

### Protect access to Solr

You may need some more commands to protect install. Check the default port 8983.
The simpler solution is to close this port with your firewall. Else, you may
need to add a user control to the admin board. Search on your not-favorite
search engine to add such a protection.

### Upgrade Solr

Before upgrade, you should backup the folder `/var/solr` if you want to upgrade
a previous config:

```bash
cd /opt
java -version
#sudo apt install default-jre
wget --no-check-certificate https://www.eu.apache.org/dist/lucene/solr/6.6.6/solr-6.6.6.tgz
tar zxvf solr-6.6.6.tgz solr-6.6.6/bin/install_solr_service.sh --strip-components=2
# The "-f" means "upgrade". The symlink /opt/solr is automatically updated.
sudo bash ./install_solr_service.sh solr-6.6.6.tgz -f
rm solr-6.6.6.tgz
rm install_solr_service.sh
# See below to upgrade the indexes.
```

### Uninstall Solr

When Solr is installed manually, there is no automatic uninstallation process.
The next commands are dangerous, so check the commands above twice before
executing, in particular don’t add whitespace after the slashs "/".

```bash
sudo systemctl stop solr
sudo update-rc.d -f solr remove
sudo rm /etc/init.d/solr
sudo rm /etc/default/solr.in.sh
sudo rm -r /opt/solr
sudo rm -r /opt/solr-6.6.6
# Only if you want to remove your indexes. WARNING: this will remove your configs too.
# sudo rm -r /var/solr
sudo deluser --remove-home solr
sudo deluser --group solr
```

The config and the data located in `/var/solr/data` by default can be removed
too.


Solr management <a id="solr-management"></a>
---------------

### Create a config

At least one index ("node", "collection" or "core")  should be created in Solr
to be used with Omeka. The simpler is to create one via the command line to
avoid permissions issues.

```
sudo su - solr -c "/opt/solr/bin/solr create -c omeka -n data_driven_schema_configs"
```

Here, the user `solr` launches the command `solr` to create the node `omeka`,
and it will use the default config schema `data_driven_schema_configs`. This
schema simplifies the management of fields, because they are guessed from the
data.

You can check it via the web interface at [http://localhost:8983/solr/#/omeka].
Here, the path to set in the config of the node in Omeka S is `solr/omeka`.

The config files are saved in `/var/solr/data` by default.

### Upgrade a config

If you choose a data driven schema, you can remove it and create a new one with
the same name.

```
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


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.

Note: the config of SolR is saved in `/var/solr/data` by default.


Troubleshooting
---------------

See online issues on the [module issues] page on GitHub.


License
-------

This module is published under the [CeCILL v2.1] licence, compatible with
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
* Copyright Daniel Berthereau, 2017-2020 (see [Daniel-KM])
* Copyright Paul Sarrassat, 2018


[Solr]: https://github.com/BibLibre/Omeka-S-module-Solr
[Omeka S]: https://omeka.org/s
[Search]: https://github.com/biblibre/omeka-s-module-Search
[Apache Solr]: https://lucene.apache.org/solr/
[Solr module]: https://github.com/biblibre/omeka-s-module-Solr
[Installing a module]: http://dev.omeka.org/docs/s/user-manual/modules/#installing-modules
[documentation]: https://lucene.apache.org/solr/guide/7_5/the-dismax-query-parser.html#q-alt-parameter
[Solr PHP extension]: https://pecl.php.net/package/solr
[below]: #manage-solr
[below for Debian]: #solr-install
[below "Solr management"]: #solr-management
[1.8]: https://lucene.apache.org/solr/7_2_1/SYSTEM_REQUIREMENTS.html
[1.7 u55]: https://lucene.apache.org/solr/5_5_5/SYSTEM_REQUIREMENTS.html
[http://localhost:8983]: http://localhost:8983
[http://localhost:8983/solr/#/omeka]: http://localhost:8983/solr/#/omeka
[solr service gist]: https://gist.github.com/Daniel-KM/1fb475a47340d7945fa6c47c945707d0
[Solr documentation]: https://lucene.apache.org/solr/resources.html
[module issues]: https://github.com/BibLibre/Omeka-S-module-Solr/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[BibLibre]: https://github.com/biblibre
[Daniel-KM]: https://github.com/Daniel-KM "Daniel Berthereau"
