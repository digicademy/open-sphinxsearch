# Open Sphinx Search

Open Sphinx Search provides a RESTful [OpenSearch API](http://www.opensearch.org/Home) 
to the [Sphinx Fulltext Searchserver](http://sphinxsearch.com/). The REST interface is
fully configurable and the output fully customizable with templates.

## Basics

Open Sphinx Search
is PHP based and uses the [Slim Framework](https://www.slimframework.com/) (currently still
in version 2) for the HTTP interface, the [Sphinx PHP API](https://github.com/romainneutron/Sphinx-Search-API-PHP-Client)
and [Twig](https://twig.symfony.com/) as Template Engine.

Open Sphinx Search has been successfully tested and is working with PHP 5.4.x - 7.x and Sphinx 2.x.
It might work on more recent versions of Sphinx as well. 

This is beta software (fully usable) but documentation is still missing. Future version
will be refactored to employ the current versions of the used PHP libraries and Sphinx. 
The plan is to keep the currently implemented functionality as stable as possible during
refactoring and provide easy migration path (if necessary at all).

If you find any issues in the current version do not hesitate to report them in our 
[issue tracker](https://github.com/digicademy/open-sphinxsearch/issues) or send in pull requests.

### Installation

Open Sphinx Search uses Composer as a package and installation manager. The installation procedure
is as follows:

1. Install [Composer ](https://getcomposer.org)
2. Clone the [GitHub repository](https://github.com/digicademy/open-sphinxsearch) to a local directory of your choice 
   (*not* your webroot).
3. CD into the project directory and use `composer install` to install the dependencies
4. Copy *configuration.example.json* to *configuration.json* and modify the file with your settings
   (use configuration.example.full.json as a reference for all possible values)
5. Point your webserver (vhost) to the *public* directory of your installation and make
   sure that all requests are routed through the index.php file (an .htaccess file for Apache is included) in the 
   public directory, for Nginx you need to tweak your vhost configuration.

Open your browser and navigate to the URL under which you have installed Open Sphinxsearch. At the base URL
you will get a simple HTML page that references the dynamically generated OpenSearch description file. Next 
in the URL are your configured index names. Each configured index provides the following four REST entry points:  
*search* (for the fulltext search), *suggest* (for Open Search suggestions queries), *keywords* (for disambiguating
submitted keywords according to index rules) and *excerpts* (for submitting snippets that should be returned with
highlighted keywords according to index rules).

### Configuration

Yet to come.

### Index setup

Yet to come.

## Endpoints

Yet to come.

### Search

Yet to come.

### Query settings

Yet to come.

#### By configuration only

Yet to come.

#### By configuration or URL parameter

Yet to come.

#### By URL parameter only

Yet to come.

### Suggest

Yet to come.

### Keywords

Yet to come.

### Excerpts

Yet to come.

## Templating

Yet to come.

## Credits

Released under MIT license.
@author: <a href="https://orcid.org/0000-0002-0953-2818">Torsten Schrade</a> (<a href="https://github.com/metacontext">@metacontext</a>)
