<?php

namespace Digicademy\OpenSphinxSearch;

/**
 * Copyright notice
 *
 * Open Sphinx Search - OpenSearch HTTP API for Sphinx Search Server
 *
 * (c) 2014 - 2017 Torsten Schrade <Torsten.Schrade@adwmainz.de>
 *
 * Digital Academy <http://www.digitale-akademie.de>
 * Academy of Sciences and Literatur | Mainz <http://www.adwmainz.de>
 *
 * Licensed under MIT License (MIT)
 */

require __DIR__ . '/../vendor/autoload.php';

/////////////////////////////////////////////////////////////////////////////
// load configuration
/////////////////////////////////////////////////////////////////////////////

$configuration = json_decode(file_get_contents(__DIR__ . '/../configuration.json'));

/////////////////////////////////////////////////////////////////////////////
// slim app settings
/////////////////////////////////////////////////////////////////////////////

// initialize Slim
$app = new \Slim\Slim(array(
    'view' => new \Slim\Views\Twig(),
    'configuration' => $configuration
));

// enable template based debugging
if($configuration->twig->debug === true) {
    $app->view->parserExtensions = array(new \Twig_Extension_Debug());
    $app->view->parserOptions = ['debug' => true];
}

// halt if no valid configuration could be loaded
if (is_object($configuration) === false) {
    // working around slim limitation @see: http://bit.ly/1pisEJZ
    header('HTTP 1.1 Internal server error', true, 500);
    $app->halt(500, 'Configuration error: No configuration.json file could be found');
}

// custom 404 handler
$app->notFound(function () use ($app) {
    $app->render('404.xml', array('rooturi' => $app->request->getRootUri()));
});

////////////////////////////////////////////////////////////////////////////
// sphinxClient settings
/////////////////////////////////////////////////////////////////////////////

// sphinx client settings per request by configuration and/or URL parameters
$app->container->singleton('sphinxClient', function () use ($app) {

    // set the calling index

    if (strrpos($app->request->getPath(), '/search')) {
        $index = str_replace($app->request->getRootUri() . '/', '', substr($app->request->getPath(), 0, -7));
    }
    if (strrpos($app->request->getPath(), '/suggest')) {
        $index = str_replace($app->request->getRootUri() . '/', '', substr($app->request->getPath(), 0, -8));
    }
    if (strrpos($app->request->getPath(), '/keywords')) {
        $index = str_replace($app->request->getRootUri() . '/', '', substr($app->request->getPath(), 0, -9));
    }
    if (strrpos($app->request->getPath(), '/excerpts')) {
        $index = str_replace($app->request->getRootUri() . '/', '', substr($app->request->getPath(), 0, -9));
    }

    // override general settings with possible index related settings

    $configuration = $app->config('configuration');

    if (is_object($configuration->sphinx->indexes->{$index}) && is_object($configuration->sphinx->indexes->{$index}->settings)) {
        foreach ($configuration->sphinx->indexes->{$index}->settings as $key => $value) {
            $configuration->sphinx->settings->{$key} = $value;
        }
    }

    if (is_object($configuration->sphinx->indexes->{$index}) && property_exists($configuration->sphinx->indexes->{$index},
            'allowParameters') && $configuration->sphinx->indexes->{$index}->allowParameters) {
        $configuration->sphinx->settings->allowParameters = explode(',',
            $configuration->sphinx->indexes->{$index}->allowParameters);
    }

    if (is_object($configuration->sphinx->indexes->{$index}) && property_exists($configuration->sphinx->indexes->{$index},
            'allowAttributes') && $configuration->sphinx->indexes->{$index}->allowAttributes) {
        foreach ($configuration->sphinx->indexes->{$index}->allowAttributes as $key => $values) {
            $configuration->sphinx->settings->allowAttributes->{$key} = explode(',', $values);
        }
    }

    if (is_object($configuration->sphinx->indexes->{$index}) && property_exists($configuration->sphinx->indexes->{$index},
            'languages') && $configuration->sphinx->indexes->{$index}->languages) {
        $configuration->sphinx->settings->languages = explode(',',
            $configuration->sphinx->indexes->{$index}->languages);
    }

    $app->config('configuration', $configuration);

    // instantiate client and configuration

    $sphinxClient = new \SphinxClient();
    $sphinxSettings = $app->config('configuration')->sphinx->settings;

    // settings by configuration only

    if ($sphinxSettings->host) {
        $sphinxClient->SetServer($sphinxSettings->host, $sphinxClient->_port);
    }

    if ((int)$sphinxSettings->port > 0) {
        $sphinxClient->SetServer($sphinxClient->_host, (int)$sphinxSettings->port);
    }

    if (property_exists($sphinxSettings, 'maxquerytime') && (int)$sphinxSettings->maxquerytime > 0) {
        $sphinxClient->SetMaxQueryTime((int)$sphinxSettings->maxquerytime);
    }

    if (property_exists($sphinxSettings, 'indexweights') && count($sphinxSettings->indexweights) > 0) {
        $sphinxClient->SetIndexWeights(array_map('intval', get_object_vars($sphinxSettings->indexweights)));
    }

    if (property_exists($sphinxSettings,
            'retrycount') && (int)$sphinxSettings->retrycount > 0 && property_exists($sphinxSettings,
            'retrydelay') && (int)$sphinxSettings->retrydelay > 0) {
        $sphinxClient->SetRetries((int)$sphinxSettings->retrycount, (int)$sphinxSettings->retrydelay);
    }

    // setting override by 1. configuration 2. request argument (but only if in allowParameters whitelist)

    if (property_exists($sphinxSettings, 'mode') && (int)$sphinxSettings->mode > 0 && (int)$sphinxSettings->mode < 7) {
        $sphinxClient->SetMatchMode((int)$sphinxSettings->mode);
    }
    if ((int)$app->request->get('mode') > 0 && (int)$app->request->get('mode') < 7 && property_exists($sphinxSettings,
            'allowParameters') && in_array('mode', $sphinxSettings->allowParameters)) {
        $sphinxClient->SetMatchMode((int)$app->request->get('mode'));
    }

    if (property_exists($sphinxSettings, 'sortby') && $sphinxSettings->sortby) {
        $sortby = $sphinxSettings->sortby;
        $sphinxClient->SetSortMode((int)$sphinxClient->_sort, $sortby);
    }
    if ($app->request->get('sortby') && property_exists($sphinxSettings, 'allowParameters') && in_array('sortby',
            $sphinxSettings->allowParameters)) {
        $sortby = $app->request->get('sortby');
        $sphinxClient->SetSortMode((int)$sphinxClient->_sort, $sortby);
    }
    if (!$sortby) $sortby = '@weight DESC'; // needs to be set - otherwise assert fails on PHP 5

    if (property_exists($sphinxSettings, 'sort') && (int)$sphinxSettings->sort > 0 && (int)$sphinxSettings->sort < 6) {
        $sphinxClient->SetSortMode((int)$sphinxSettings->sort, $sortby);
    }
    if ((int)$app->request->get('sort') > 0 && (int)$app->request->get('sort') < 6 && property_exists($sphinxSettings,
            'allowParameters') && in_array('sort', $sphinxSettings->allowParameters)) {
        $sphinxClient->SetSortMode((int)$app->request->get('sort'), $sortby);
    }

    if (property_exists($sphinxSettings,
            'ranker') && (int)$sphinxSettings->ranker > 0 && (int)$sphinxSettings->ranker < 9) {
        $sphinxClient->SetRankingMode((int)$sphinxSettings->ranker);
    }
    if ((int)$app->request->get('ranker') > 0 && (int)$app->request->get('ranker') < 9 && property_exists($sphinxSettings,
            'allowParameters') && in_array('ranker', $sphinxSettings->allowParameters)) {
        $sphinxClient->SetRankingMode((int)$app->request->get('ranker'));
    }

    if (property_exists($sphinxSettings, 'min_id') && property_exists($sphinxSettings,
            'max_id') && (int)$sphinxSettings->min_id > 0 && (int)$sphinxSettings->max_id > 0 && $sphinxSettings->min_id <= (int)$sphinxSettings->max_id) {
        $sphinxClient->SetIDRange((int)$sphinxSettings->min_id, (int)$sphinxSettings->max_id);
    }
    if ((int)$app->request->get('min_id') > 0 && (int)$app->request->get('max_id') > 0 && (int)$app->request->get('min_id') <= (int)$app->request->get('max_id') && property_exists($sphinxSettings,
            'allowParameters') && in_array('min_id', $sphinxSettings->allowParameters) && in_array('max_id',
            $sphinxSettings->allowParameters)) {
        $sphinxClient->SetIDRange((int)$app->request->get('min_id'), (int)$app->request->get('max_id'));
    }

    if (property_exists($sphinxSettings, 'select') && $sphinxSettings->select) {
        $sphinxClient->SetSelect($sphinxSettings->select);
    }
    if ($app->request->get('select') && property_exists($sphinxSettings, 'allowParameters') && in_array('select',
            $sphinxSettings->allowParameters)) {
        $sphinxClient->SetSelect($app->request->get('select'));
    }

    // &fieldweights[fieldname]=3

    if (property_exists($sphinxSettings, 'fieldweights') && count($sphinxSettings->fieldweights) > 0) {
        $sphinxClient->SetFieldWeights(array_map('intval', get_object_vars($sphinxSettings->fieldweights)));
    }
    if ($app->request->get('fieldweights') && count($app->request->get('fieldweights')) > 0 && property_exists($sphinxSettings,
            'allowParameters') && in_array('fieldweights', $sphinxSettings->allowParameters)) {
        $fieldweightsGET = $app->request->get('fieldweights');
        foreach ($fieldweightsGET as $fieldname => $value) {
            if (in_array($fieldname, $sphinxSettings->allowAttributes->fieldweights) === false) {
                unset($fieldweightsGET[$fieldname]);
            }
        }
        (property_exists($sphinxSettings, 'fieldweights') && (count($sphinxSettings->fieldweights) > 0)) ?
            $fieldweights = array_merge(array_map('intval', get_object_vars($sphinxSettings->fieldweights)),
                array_map('intval', $fieldweightsGET)) :
            $fieldweights = array_map('intval', $fieldweightsGET);
        $sphinxClient->SetFieldWeights($fieldweights);
    }

    // group by settings

    if (property_exists($sphinxSettings, 'groupby') && $sphinxSettings->groupby) {
        $sphinxClient->SetGroupBy($sphinxSettings->groupby, $sphinxClient->_groupfunc, $sphinxClient->_groupsort);
    }
    if ($app->request->get('groupby') && property_exists($sphinxSettings, 'allowParameters') && in_array('groupby',
            $sphinxSettings->allowParameters)) {
        $sphinxClient->SetGroupBy($app->request->get('groupby'), $sphinxClient->_groupfunc, $sphinxClient->_groupsort);
    }

    if (property_exists($sphinxSettings, 'groupfunc') && $sphinxSettings->groupfunc) {
        $sphinxClient->SetGroupBy($sphinxClient->_groupby, $sphinxSettings->groupfunc, $sphinxClient->_groupsort);
    }
    if ((int)$app->request->get('groupfunc') > 0 && (int)$app->request->get('groupfunc') < 6 && property_exists($sphinxSettings,
            'allowParameters') && in_array('groupfunc', $sphinxSettings->allowParameters)) {
        $sphinxClient->SetGroupBy($sphinxClient->_groupby, (int)$app->request->get('groupfunc'),
            $sphinxClient->_groupsort);
    }

    if (property_exists($sphinxSettings, 'groupsort') && $sphinxSettings->groupsort) {
        $sphinxClient->SetGroupBy($sphinxClient->_groupby, $sphinxClient->_groupfunc, $sphinxSettings->groupsort);
    }
    if ($app->request->get('groupsort') && property_exists($sphinxSettings, 'allowParameters') && in_array('groupsort',
            $sphinxSettings->allowParameters)) {
        $sphinxClient->SetGroupBy($sphinxClient->_groupby, $sphinxClient->_groupfunc, $app->request->get('groupsort'));
    }

    if (property_exists($sphinxSettings, 'groupdistinct') && $sphinxSettings->groupdistinct) {
        $sphinxClient->SetGroupDistinct($sphinxSettings->groupdistinct);
    }
    if ($app->request->get('groupdistinct') && property_exists($sphinxSettings,
            'allowParameters') && in_array('groupdistinct', $sphinxSettings->allowParameters)) {
        $sphinxClient->SetGroupDistinct($app->request->get('groupdistinct'));
    }

    // filters
    $filters = array();

    // &set_filter[ATTR][values]=1,2,3,4&set_filter[ATTR][exclude]=1
    if (property_exists($sphinxSettings, 'set_filter') && count($sphinxSettings->set_filter) > 0) {
        $filters['set_filter'] = $sphinxSettings->set_filter;
    }
    if ($app->request->get('set_filter') && count($app->request->get('set_filter')) > 0 && property_exists($sphinxSettings,
            'allowParameters') && in_array('set_filter', $sphinxSettings->allowParameters)) {
        $setFilterGET = json_decode(json_encode($app->request->get('set_filter')));
        foreach ($setFilterGET as $attribute => $values) {
            if (in_array($attribute, $sphinxSettings->allowAttributes->set_filter)) {
                $filters['set_filter']->{$attribute} = $values;
            }
        }
    }

    // &set_filter_range[ATTR][min]=1&set_filter_range[ATTR][max]=10&set_filter_range[ATTR][exclude]=1
    if (property_exists($sphinxSettings, 'set_filter_range') && count($sphinxSettings->set_filter_range) > 0) {
        $filters['set_filter_range'] = $sphinxSettings->set_filter_range;
    }
    if ($app->request->get('set_filter_range') && count($app->request->get('set_filter_range')) > 0 && property_exists($sphinxSettings,
            'allowParameters') && in_array('set_filter_range', $sphinxSettings->allowParameters)) {
        $filterRangeGET = json_decode(json_encode($app->request->get('set_filter_range')));
        foreach ($filterRangeGET as $attribute => $range) {
            if (in_array($attribute, $sphinxSettings->allowAttributes->set_filter_range)) {
                $filters['set_filter_range']->{$attribute} = $range;
            }
        }
    }

    // &set_filter_floatrange[ATTR][min]=1&set_filter_floatrange[ATTR][max]=10&set_filter_floatrange[ATTR][exclude]=1
    if (property_exists($sphinxSettings,'set_filter_floatrange') && count($sphinxSettings->set_filter_floatrange) > 0) {
        $filters['set_filter_floatrange'] = $sphinxSettings->set_filter_floatrange;
    }
    if ($app->request->get('set_filter_floatrange') && count($app->request->get('set_filter_floatrange')) > 0 && property_exists($sphinxSettings,
            'allowParameters') && in_array('set_filter_floatrange', $sphinxSettings->allowParameters)) {
        $filterFloatRangeGET = json_decode(json_encode($app->request->get('set_filter_floatrange')));
        foreach ($filterFloatRangeGET as $attribute => $range) {
            if (in_array($attribute, $sphinxSettings->allowAttributes->set_filter_floatrange)) {
                $filters['set_filter_floatrange']->{$attribute} = $range;
            }
        }
    }

    // &set_geo_anchor[attrlat]=ATTR&set_geo_anchor[attrlong]=ATTR&set_geo_anchor[lat]=51.0&set_geo_anchor[long]=10.2
    if (property_exists($sphinxSettings, 'set_geo_anchor') && count($sphinxSettings->set_geo_anchor) > 0) {
        $filters['set_geo_anchor'] = $sphinxSettings->set_geo_anchor;
    }
    if ($app->request->get('set_geo_anchor') && count($app->request->get('set_geo_anchor')) > 0 && property_exists($sphinxSettings,
            'allowParameters') && in_array('set_geo_anchor', $sphinxSettings->allowParameters)) {
        $geoAnchorGET = json_decode(json_encode($app->request->get('set_geo_anchor')));
        if (in_array($geoAnchorGET->attrlat,
                $sphinxSettings->allowAttributes->set_geo_anchor) && property_exists($sphinxSettings,
                'allowParameters') && in_array($geoAnchorGET->attrlong,
                $sphinxSettings->allowAttributes->set_geo_anchor)) {
            $filters['set_geo_anchor'] = $geoAnchorGET;
        }
    }

    // $set_filter_string[ATTR]=VALUE // only newest Sphinx API / not yet supported

    // language settings
    if ((string)$app->request->get('lang') && property_exists($sphinxSettings,
            'allowParameters') && in_array('lang', $sphinxSettings->allowParameters)
            && in_array($app->request->get('lang'), $sphinxSettings->languages)) {
        $langId = array_search($app->request->get('lang'), $sphinxSettings->languages);
        $filters['set_filter']->sys_language_uid = $langId;
    }
    // set all filters
    if ($filters && count($filters) > 0) {
        foreach ($filters as $type => $filter) {
            if (is_object($filter)) {
                foreach (get_object_vars($filter) as $attribute => $settings) {
                    $exclude = false;
                    $values = '';
                    $min = null;
                    $max = null;

                    if (is_object($settings) && property_exists($settings, 'exclude')) {
                        if ((int)$settings->exclude === 1) $exclude = true;
                    };

                    switch ($type) {
                        case 'set_filter':
                            if (is_object($settings) && property_exists($settings, 'values')) {
                                $values = array_map('intval', explode(',', $settings->values));
                            } else {
                                $values = array_map('intval', explode(',', $settings));
                            }
                            $sphinxClient->SetFilter($attribute, $values, $exclude);
                            break;
                        case 'set_filter_range':
                            $min = (int)$settings->min;
                            $max = (int)$settings->max;
                            if ($min >= 0 && $max > 0 && $min < $max) {
                                $sphinxClient->SetFilterRange($attribute, $min, $max, $exclude);
                            }
                            break;
                        case 'set_filter_floatrange':
                            $min = (float)$settings->min;
                            $max = (float)$settings->max;
                            if ($min >= 0 && $max > 0 && $min < $max) {
                                $sphinxClient->SetFilterFloatRange($attribute, $min, $max, $exclude);
                            }
                            break;
                        case 'set_geo_anchor':
                            if ($settings->attrlat && $settings->attrlong && is_float($settings->lat) && is_float($settings->long)) {
                                $sphinxClient->SetGeoAnchor($settings->attrlat, $settings->attrlong, $settings->lat,
                                    $settings->long);
                            }
                            break;
                    }
                }
            }
        }
    }

    // settings by URL parameter only

    if ((int)$app->request->get('offset') > 0 && property_exists($sphinxSettings,
            'allowParameters') && in_array('offset', $sphinxSettings->allowParameters)) {
        $sphinxClient->SetLimits((int)$app->request->get('offset'), $sphinxClient->_limit);
    }

    if ((int)$app->request->get('limit') > 0 && property_exists($sphinxSettings,
     'allowParameters') && in_array('limit', $sphinxSettings->allowParameters)) {
        $sphinxClient->SetLimits($sphinxClient->_offset, (int)$app->request->get('limit'));
    }

    if ((int)$app->request->get('maxmatches') > 0 && property_exists($sphinxSettings,
            'allowParameters') && in_array('maxmatches', $sphinxSettings->allowParameters)) {
        $sphinxClient->SetLimits($sphinxClient->_offset, $sphinxClient->_limit, (int)$app->request->get('maxmatches'));
    }

    if ((int)$app->request->get('cutoff') > 0 && property_exists($sphinxSettings,
            'allowParameters') && in_array('cutoff', $sphinxSettings->allowParameters)) {
        $sphinxClient->SetLimits($sphinxClient->_offset, $sphinxClient->_limit, $sphinxClient->_maxmatches,
            (int)$app->request->get('cutoff'));
    }

    // &override[ATTR][type]=3&override[ATTR][docs][123]=55
    if ($app->request->get('override') && count($app->request->get('override')) > 0 && property_exists($sphinxSettings,
            'allowParameters') && in_array('override', $sphinxSettings->allowParameters)) {
        foreach ($app->request->get('override') as $attribute => $values) {
            if ((int)$values['type'] > 0 && $values['docs'] && property_exists($sphinxSettings,
                    'allowParameters') && in_array($attribute, $sphinxSettings->allowAttributes->override)) {
                $sphinxClient->SetOverride($attribute, (int)$values['type'], $values['docs']);
            }
        }
    }

    return $sphinxClient;
});

function setOpts($optsSettings, $optsPOST, $allowParameters)
{

    $opts = array();
    $allowParameters = explode(',', $allowParameters);

    (is_object($optsSettings)) ? $optsSettings = get_object_vars($optsSettings) : $optsSettings = array();

    if ($optsPOST && count($optsPOST) > 0) {
        foreach ($optsPOST as $option => $value) {
            if (in_array($option, $allowParameters) === false) {
                unset($optsPOST[$option]);
            }
        }
    } else {
        $optsPOST = array();
    }

    $mergedOpts = array_merge($optsSettings, $optsPOST);

    if ($mergedOpts && count($mergedOpts) > 0) {
        foreach ($mergedOpts as $option => $value) {
            switch ($option) {
                case 'limit':
                case 'around':
                case 'limit_passages':
                case 'limit_words':
                case 'start_passage_id':
                    $opts[$option] = (int)$value;
                    break;
                case 'exact_phrase':
                case 'use_boundaries':
                case 'weight_order':
                case 'query_mode':
                case 'force_all_words':
                case 'load_files':
                case 'load_files_scattered':
                case 'allow_empty':
                case 'emit_zones':
                    $opts[$option] = (boolean)$value;
                    break;
                case 'html_strip_mode':
                    if (in_array($value, array(0 => 'none', 1 => 'strip', 2 => 'index', 3 => 'retain'))) {
                        $opts[$option] = $value;
                    }
                    break;
                case 'passage_boundary':
                    if (in_array($value, array(0 => 'sentence', 1 => 'paragraph', 2 => 'zone'))) {
                        $opts[$option] = $value;
                    }
                    break;
                default:
                    $opts[$option] = $value;
                    break;
            }
        }
    }

    return $opts;
}

/////////////////////////////////////////////////////////////////////////////
// routes
/////////////////////////////////////////////////////////////////////////////

// webroot returns html page
$app->get('/', function ($indexes = array()) use ($app) {
    foreach ($app->config('configuration')->sphinx->indexes as $key => $index) {
        $indexes[$key]['path'] = $key;
        $indexes[$key]['description'] = $index->description;
    }
    $app->response->headers->set('Content-Type', 'text/html');
    $app->render('index.html', array('indexes' => $indexes));
});

// routes into defined indexes; each index can have opensearch description, search, suggest, keywords and excerpts endpoints
$app->group('/:index', function () use ($app) {

    $app->get('/', function ($index) use ($app) {

        (file_exists($app->view()->getTemplatePathname($index . '_description.xml'))) ?
            $template = $index . '_description.xml' :
            $template = 'description.xml';

        if (is_object($app->config('configuration')->sphinx->indexes->{$index})) {
            $app->response->headers->set('Content-Type', 'application/opensearchdescription+xml');
            $app->render($template, array(
                'host' => $app->request->getHost(),
                'port' => $app->request->getPort(),
                'path' => $app->request->getPath()
            ));
        } else {
            $app->notFound();
        }
    });

    $app->map('/search', function ($index) use ($app) {

        (file_exists($app->view()->getTemplatePathname($index . '_response.xml'))) ?
            $template = $index . '_response.xml' :
            $template = 'response.xml';

        if (is_object($app->config('configuration')->sphinx->indexes->{$index})) {

            $settings = $app->config('configuration')->sphinx->indexes->{$index};

            if (property_exists($settings, 'alias') && $settings->alias) {
                $index = $settings->alias;
            }

            $sphinxClient = $app->sphinxClient;

            $q = $app->request()->get('q');

            if (property_exists($settings, 'search') && property_exists($settings->search,
                    'escapeQ') && (int)$settings->search->escapeQ === 1) {
                $q = $sphinxClient->EscapeString($q);
            }
            if (property_exists($settings, 'search') && property_exists($settings->search,
                    'wrapQ') && count($wrap = explode('|', $settings->search->wrapQ)) === 2) {
                $q = trim($wrap[0] . $q . $wrap[1]);
            }

            $result = $sphinxClient->query($q, $index);

            if ($result !== false) {
                $app->render($template, array(
                    'host' => $app->request->getHost(),
                    'port' => $app->request->getPort(),
                    'path' => substr($app->request->getPath(), 0, -7),
                    'q' => urlencode($q),
                    'startIndex' => ($sphinxClient->_offset * $sphinxClient->_limit) + 1,
                    'startPage' => $sphinxClient->_offset,
                    'itemsPerPage' => $sphinxClient->_limit,
                    'result' => $result
                ));
            } else {
                $app->render('400.xml', array('error' => $sphinxClient->_error), 400);
            }

        } else {
            $app->notFound();
        }

    })->via('GET');

    $app->get('/suggest', function ($index) use ($app) {

        (file_exists($app->view()->getTemplatePathname($index . '_suggest.json'))) ?
            $template = $index . '_suggest.json' :
            $template = 'suggest.json';

        if (is_object($app->config('configuration')->sphinx->indexes->{$index})) {

            $settings = $app->config('configuration')->sphinx->indexes->{$index};

            if (property_exists($settings, 'alias') && $settings->alias) {
                $index = $settings->alias;
            }

            $sphinxClient = $app->sphinxClient;

            $q = $app->request()->get('q');

            if (property_exists($settings, 'suggest') && property_exists($settings->suggest,
                    'escapeQ') && (int)$settings->suggest->escapeQ === 1) {
                $q = $sphinxClient->EscapeString($q);
            }
            if (property_exists($settings, 'suggest') && property_exists($settings->suggest,
                    'wrapQ') && count($wrap = explode('|', $settings->suggest->wrapQ)) === 2) {
                $q = trim($wrap[0] . $q . $wrap[1]);
            }

            $result = $sphinxClient->query($q, $index);

            if ($result !== false) {
                $app->response->headers->set('Content-Type', 'application/x-suggestions+json');
                $app->render($template, array(
                    'q' => $q,
                    'result' => $result
                ));
            } else {
                $app->render('400.xml', array('error' => $sphinxClient->_error), 400);
            }

        } else {
            $app->notFound();
        }

    });

    $app->get('/keywords', function ($index) use ($app) {

        (file_exists($app->view()->getTemplatePathname($index . '_keywords.xml'))) ?
            $template = $index . '_keywords.xml' :
            $template = 'keywords.xml';

        if (is_object($app->config('configuration')->sphinx->indexes->{$index})) {

            $settings = $app->config('configuration')->sphinx->indexes->{$index};

            if (property_exists($settings, 'alias') && $settings->alias) {
                $index = $settings->alias;
            }

            $sphinxClient = $app->sphinxClient;

            $q = $app->request()->get('q');

            ((int)$app->request()->get('hits') === 1 || (property_exists($settings,
                        'keywords') && property_exists($settings->keywords,
                        'hits') && (int)$settings->keywords->hits === 1)) ?
                $hits = true : $hits = false;

            $result = $sphinxClient->BuildKeywords($q, $index, $hits);

            if ($result !== false) {
                if ($app->request()->get('format') === "json") {
                    $app->response->headers->set('Content-Type', 'application/json');
                    $app->halt(200, json_encode($result));
                } else {
                    $app->render($template, array(
                        'result' => $result,
                        'count' => count($result),
                    ));
                }
            } else {
                $app->render('400.xml', array('error' => $sphinxClient->_error), 400);
            }

        } else {
            $app->notFound();
        }
    });

    $app->post('/excerpts', function ($index) use ($app) {

        (file_exists($app->view()->getTemplatePathname($index . '_excerpts.xml'))) ?
            $template = $index . '_excerpts.xml' :
            $template = 'excerpts.xml';

        if (is_object($app->config('configuration')->sphinx->indexes->{$index})) {

            $settings = $app->config('configuration')->sphinx->indexes->{$index};

            if (property_exists($settings, 'alias') && $settings->alias) {
                $index = $settings->alias;
            }

            $sphinxClient = $app->sphinxClient;

            $docs = $app->request()->post('docs');
            $words = $app->request()->post('words');
            if (property_exists($settings, 'excerpts') === false) {
                $settings->excerpts = null;
            }
            if (property_exists($settings, 'allowParameters') === false) {
                $settings->allowParameters = array();
            }
            $opts = setOpts($settings->excerpts, $app->request()->post('opts'), $settings->allowParameters);

            $result = $sphinxClient->BuildExcerpts($docs, $index, $words, $opts);

            if ($result !== false) {
                if ($app->request()->get('format') === "json") {
                    $app->response->headers->set('Content-Type', 'application/json');
                    $app->halt(200, json_encode($result));
                } else {
                    $app->render($template, array(
                        'result' => $result,
                        'count' => count($result),
                    ));
                }
            } else {
                $app->render('400.xml', array('error' => $sphinxClient->_error), 400);
            }

        } else {
            $app->notFound();
        }

    });

});

// sphinx status method only sphinx api trunk - not yet supported
//$app->get('/status', function() use ($app) {
//});

/////////////////////////////////////////////////////////////////////////////
// run
/////////////////////////////////////////////////////////////////////////////

#$app->response->headers->set('Content-Type', 'application/xml');
$app->response->headers->set('Content-Type', 'text/xml');
$app->view()->setTemplatesDirectory($app->settings['configuration']->slim->templatesPath);
$app->run();
