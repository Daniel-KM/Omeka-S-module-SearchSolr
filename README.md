Solr (module for Omeka S)
=========================

[![Build Status](https://travis-ci.org/biblibre/omeka-s-module-Solr.svg?branch=master)](https://travis-ci.org/biblibre/omeka-s-module-Solr)

[Solr] is a module for [Omeka S] that provides a [Search] adapter for [Apache Solr].


Installation
------------

Uncompress the zip inside the folder `modules` and rename it `Solr`.

See general end user documentation for [Installing a module].

### Requirements

- [Solr PHP extension] (>= 2.0.0). It must be enabled for the CLI as well as the
  web server.
- A running Solr 5 instance. It may work with other versions, but it's only
  tested with Solr 5.
- [Search]


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
3. In Search admin
    1. Add a new index with name `Default` or whatever you want, using the Solr
       adapter and the `default` node.
    2. Launch the indexation by clicking on the "reindex" button (two arrows
       forming a circle).
    3. Add a page with name `Default` or whatever you want, a path to access the
       page, for example `search`, the created index (`Default (Solr)` here, and
       a form (`Basic`).
    4. In the page configuration, you can enable/disable facet and sort fields
       by drag-drop. The order of the fields will be the one that will be used
       for display. Note that some fields seem duplicated, but they aren't. Some
       of them allow to prepare search indexes and some other facets or sort
       indexes. Some of them may be used for all uses.
       For example, you can use `dcterms_type_ss`, `dcterms_subject_ss`,
       `resource_class_s`, `item_set_dcterms_title_ss`, `dcterms_creator_ss`,
       `dcterms_date_s`, `dcterms_spatial_ss `, `dcterms_language_ss` and
       `dcterms_rights_ss` as facets, and `Relevance`, `dcterms_title_s`,
       `dcterms_date_s`, and `dcterms_creator_s` as sort fields.
    5. Edit the name of the label that will be used for facets and sort fields
       in the same page. The string will be automatically translated if it
       exists in Omeka.
4. In admin or site settings
    1. To access to the Solr search form, enable it in the settings, so it will
       be available in the specified path: `https://example.com/s/my-site/search`
       or `https://example.com/admin/search` in this example.
    2. Optionally, add a custom navigation link to the search page in the
       navigation settings of the site.

The search form should appear. Type some text then submit the form to display
the results as grid or as list. The page can be themed.

**IMPORTANT**

The Search module  does not replace the default search page neither the default
search engine. So the theme should be updated.

Don’t forget to check Search facets and sort fields of each search page each
time that the list of node fields is modified: the fields that don’t exist
anymore are removed; the new ones are not added; the renamed ones are updated,
but issues may occur in case of duplicate names.

Furthermore, a check should be done when a field has the same name for items and
item sets.

Don’t forget to reindex the fields each time the Solr config is updated too.


Solr install on Debian <a name="solr-install"></a>
----------------------

The packaged release of Solr on Debian is obsolete (3.6.2), so it should be
installed via the original sources. If you have a build or a development server,
it’s recommended to create a Solr package outside of the server and to install
it via `dpkg`.

```bash
# Check if java is installed.
java -version
# If not installed, install it (uncomment)
#sudo apt install default-jdk
# The certificate is currently obsolete on Apache server, so don’t check it.
# This module was primarly designed for Solr 5. Not checked above yet.
wget --no-check-certificate https://www.eu.apache.org/dist/lucene/solr/5.5.5/solr-5.5.5.tgz
# Extract the install script
tar zxvf solr-5.5.5.tgz solr-5.5.5/bin/install_solr_service.sh --strip-components=2
# Launch the install script (by default, Solr is installed in /opt; check other options if needed)
sudo bash ./install_solr_service.sh solr-5.5.5.tgz
# Add a symlink to simplify management.
sudo ln -s /opt/solr-5.5.5 /opt/solr
# Clean the sources.
rm solr-5.5.5.tgz
```

Solr may be managed as a system service:

```bash
sudo systemctl stop solr
sudo systemctl start solr
sudo systemctl status solr
```

Solr is automatically launched and available in your browser at [http://localhost:8983].

Solr is available via command line too at `/opt/solr/bin/solr`.


Solr management <a name="solr-management"></a>
---------------

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


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


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


Contact
-------

Current maintainers of the module:

* BibLibre (see [BibLibre])
* Daniel Berthereau (see [Daniel-KM])


Copyright
---------

See commits for full list of contributors.

* Copyright BibLibre, 2016-2017
* Copyright Daniel Berthereau, 2017-2018


[Solr]: https://github.com/BibLibre/Omeka-S-module-Solr
[Omeka S]: https://omeka.org/s
[Search]: https://github.com/biblibre/omeka-s-module-Search
[Apache Solr]: https://lucene.apache.org/solr/
[Solr module]: https://github.com/biblibre/omeka-s-module-Solr
[Installing a module]: http://dev.omeka.org/docs/s/user-manual/modules/#installing-modules
[Solr PHP extension]: https://pecl.php.net/package/solr
[below]: #manage-solr
[below for Debian]: #solr-install
[below "Solr management"]: #solr-management
[http://localhost:8983]: http://localhost:8983
[http://localhost:8983/solr/#/omeka]: http://localhost:8983/solr/#/omeka
[module issues]: https://github.com/BibLibre/Omeka-S-module-Solr/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[BibLibre]: https://github.com/biblibre
[Daniel-KM]: https://github.com/Daniel-KM "Daniel Berthereau"
