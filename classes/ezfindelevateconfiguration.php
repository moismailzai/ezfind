<?php
//
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: eZ Find
// SOFTWARE RELEASE: 2.0.x
// COPYRIGHT NOTICE: Copyright (C) 2009 eZ Systems AS
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
//
//   This program is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
//
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301, USA.
//
//
// ## END COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
//

/**
 * File containing the eZFindElevateConfiguration class.
 *
 * @package eZFind
 */
class eZFindElevateConfiguration extends eZPersistentObject
{
    /**
     * XML string used to generate the elevate confguration for Solr.
     *
     * @var string
     */
    const XML_SKELETON = '<?xml version="1.0" encoding="UTF-8" ?><elevate></elevate>';

    /**
     * Character used to symbolize that the queryString will elevate a given
     * content object for ALL languages.
     *
     * @var string
     */
    const WILDCARD = '*';

    /**
     * Name of the file used by Solr to load the Elevate configuration
     *
     * @var string
     * @deprecated ( no need to directly access the configuration file. Update performed through HTTP/ReST now )
     */
    const ELEVATE_CONF_FILENAME = 'elevate.xml';

    /**
     * Name of the POST parameter used in the communication with Solr, containing the
     * new Elevate configuration.
     *
     * @var string
     */
    const CONF_PARAM_NAME = 'elevate-configuration';

    /**
     * Storing solr.ini
     * Initialized by the end of this file.
     *
     * @var eZINI
     */
    public static $solrINI;

    /**
     * Contains the last error related to synchronization of the configuration.
     * Initialized when an error occurs.
     *
     * @var string
     */
    public static $lastSynchronizationError;

    /**
     * Used as a data transmission artifact.
     * Will store the configuration XML string once it was generated by the method generateConfiguration()
     *
     * @see function generateConfiguration()
     * @var string
     */
    protected static $configurationXML = null;

    /**
     * Constructor. Will create a new configuration row.
     *
     * @param array $row Contains the values for each column in the DB.
     */
    public function __construct( $row )
    {
        $this->eZPersistentObject( $row );
    }

    /**
     * Mandatory method defining the eZFindElevationConfiguration persistent object
     *
     * @return array An array defining the eZFindElevationConfiguration persistent object
     */
    public static function definition()
    {
        return array(
            "fields" => array( "search_query"     => array(    'name' => 'searchQuery',
                                                               'datatype' => 'string',
                                                               'default' => '',
                                                               'required' => true ),
                               "contentobject_id" => array(    'name' => 'contentObjectId',
                                                               'datatype' => 'int',
                                                               'default' => 0,
                                                               'required' => true ),
                               "language_code"    => array(    'name' => 'languageCode',
                                                               'datatype' => 'string',
                                                               'default' => '',
                                                               'required' => true )
                             ),
            "keys" => array( "search_query", "contentobject_id", "language_code" ),
            "function_attributes" => array(),
            "class_name" => "eZFindElevateConfiguration",
            "sort" => array( "contentobject_id" => "asc" ),
            "name" => "ezfind_elevate_configuration"
        );
    }

    /**
     * Retrieves the elevate configuration for a given content object, possibly per language.
     *
     * @param int $objectID ID of the content object to fetch elevate configuration for
     * @param boolean $groupByLanguage Group results per language. If true, the return value will look like the following :
     * <code>
     *     array( 'eng-GB' => array( ( eZFindElevateConfiguration ) $conf1,
     *                               ( eZFindElevateConfiguration ) $conf2 ),
     *            'fre-FR' => array( ( eZFindElevateConfiguration ) $conf3 ),
     *            '*'      => array( ( eZFindElevateConfiguration ) $conf4 ),
     *                               ( eZFindElevateConfiguration ) $conf5
     *          )
     * </code>
     *
     * @param string $languageCode if filtering on language-code is required
     * @param array $limit Associative array containing the 'offset' and 'limit' keys, with integers as values, framing the result set. Example :
     * <code>
     *     array( 'offset' => 0,
     *            'limit'  => 10 )
     * </code>
     *
     * @param boolean $countOnly If only the count of matching results is needed
     * @param mixed $searchQuery Can be a string containing the search_query to filter on.
     *                           Can also be an array looking like the following, supporting fuzzy search optionnally.
     * <code>
     *    array( 'searchQuery' => ( string )  'foo bar',
     *           'fuzzy'       => ( boolean ) true      )
     * </code>
     *
     * @return mixed An array containing the eZFindElevateConfiguration objects, optionnally sorted by language code, null if error.
     */
    public static function fetchConfigurationForObject( $objectID,
                                                        $groupByLanguage = true,
                                                        $languageCode = null,
                                                        $limit = null,
                                                        $countOnly = false,
                                                        $searchQuery = null )
    {
        if ( ! is_numeric( $objectID ) )
            return null;

        $fieldFilters = $custom = null;
        $asObject = true;
        $results = array();
        $sortClause = $groupByLanguage ? array( 'language_code' => 'asc' ) : null;

        if ( $countOnly )
        {
            $limit = null;
            $asObject = false;
            $fieldFilters = array();
            $custom = array( array( 'operation' => 'count( * )',
                                    'name' => 'count' ) );
        }

        $conds = array( 'contentobject_id' => $objectID );
        if ( $languageCode and $languageCode !== '' )
            $conds['language_code'] = array( array( $languageCode, self::WILDCARD ) );

        if ( $searchQuery )
        {
            if ( !is_array( $searchQuery ) and $searchQuery != '' )
            {
                $conds['search_query'] = $searchQuery;
            }
            elseif ( array_key_exists( 'searchQuery', $searchQuery ) and $searchQuery['searchQuery'] != '' )
            {
                $conds['search_query'] = $searchQuery['fuzzy'] === true ? array( 'like', "%{$searchQuery['searchQuery']}%" ) : $searchQuery['searchQuery'];
            }
        }

        $rows = parent::fetchObjectList( self::definition(), $fieldFilters, $conds, $sortClause, $limit, $asObject, false, $custom );

        if ( $countOnly )
        {
            return $rows[0]['count'];
        }
        else
        {
            foreach( $rows as $row )
            {
                if ( $groupByLanguage )
                {
                    $results[$row->attribute( 'language_code' )][] = $row;
                }
                else
                {
                    $results[] = $row;
                }
            }
            return $results;
        }
    }

    /**
     * Retrieves the content objects elevated by a given query string, possibly per language.
     *
     * @param mixed $searchQuery Can be a string containing the search_query to filter on.
     *                           Can also be an array looking like the following, supporting fuzzy search optionnally.
     * <code>
     *    array( 'searchQuery' => ( string )  'foo bar',
     *           'fuzzy'       => ( boolean ) true      )
     * </code>
     *
     * @param string $languageCode if filtering on language-code is required
     * @param array $limit Associative array containing the 'offset' and 'limit' keys, with integers as values, framing the result set. Example :
     * <code>
     *     array( 'offset' => 0,
     *            'limit'  => 10 )
     * </code>
     *
     * @param boolean $countOnly If only the count of matching results is needed
     * @return mixed An array containing the content objects elevated by the query string, optionnally sorted by language code, null if error. If $countOnly is true,
     *               only the result count is returned.
     */
    public static function fetchObjectsForQueryString( $queryString, $groupByLanguage = true, $languageCode = null, $limit = null, $countOnly = false )
    {
        if ( ( is_string( $queryString ) and $queryString === '' ) or
             ( is_array( $queryString ) and array_key_exists( 'searchQuery', $queryString ) and $queryString['searchQuery'] == '' )
           )
        {
            return null;
        }

        $fieldFilters = $custom = null;
        $objects = array();
        $sortClause = $groupByLanguage ? array( 'language_code' => 'asc' ) : null;

        if ( !is_array( $queryString ) )
        {
            $conds['search_query'] = $queryString;
        }
        else
        {
            $conds['search_query'] = @$queryString['fuzzy'] === true ? array( 'like', "%{$queryString['searchQuery']}%" ) : $queryString['searchQuery'];
        }

        if ( $languageCode and $languageCode !== '' )
            $conds['language_code'] = array( array( $languageCode, self::WILDCARD ) );


        if ( $countOnly )
        {
            $limit = null;
            $fieldFilters = array();
            $custom = array( array( 'operation' => 'count( * )',
                                    'name' => 'count' ) );
        }

        $rows = parent::fetchObjectList( self::definition(), $fieldFilters, $conds, $sortClause, $limit, false, false, $custom );

        if ( $countOnly and $rows )
        {
            return $rows[0]['count'];
        }
        else
        {
            foreach( $rows as $row )
            {
                if ( ( $obj = eZContentObject::fetch( $row['contentobject_id'] ) ) !== null )
                {
                    if ( $groupByLanguage )
                    {
                        $objects[$row['language_code']][] = $obj;
                    }
                    else
                    {
                        $objects[] = $obj;
                    }
                }
            }
            return $objects;
        }
    }


    /**
     * Adds an elevate configuration row, optionnally for a given language.
     *
     * @param string $queryString Query string for which elevate configuration is added
     * @param int $objectID Content object for which the elevate configuration is added
     * @param string $languageCode Language code for which the elevate configuration is added. Defaults to 'all languages'
     */
    public static function add( $queryString, $objectID, $languageCode = self::WILDCARD )
    {
        $db = eZDB::instance();
        $queryString = $db->escapeString( $queryString );

        if ( $languageCode === self::WILDCARD )
        {
            self::purge( $queryString, $objectID );
        }
        else
        {
            // tring to insert an elevate configuration row for a specific language, while one already exists for all languages.
            if ( parent::fetchObject( self::definition(), null, array( 'contentobject_id' => $objectID, 'search_query' => $queryString, 'language_code' => self::WILDCARD ) ) )
                return null;
        }

        $row = array( 'search_query'     => $queryString,
                      'contentobject_id' => $objectID,
                      'language_code'    => $languageCode );

        $conf = new eZFindElevateConfiguration( $row );
        $conf->store();
        return $conf;
    }

    /**
     * Purges the configuration rows for a given query string, a given object, or both.
     *
     * @param string $queryString Query string for which elevate configuration is removed
     * @param int $objectID Content object for which the elevate configuration is removed
     * @param string $languageCode Language code for which the elevate configuration is removed. Defaults to 'all languages'
     */
    public static function purge( $queryString = '' , $objectID = null, $languageCode = null )
    {
        // check that some conditions were passed
        if ( $queryString === '' and $objectID === null and $languageCode === null )
            return false;

        if ( $queryString !== '' )
            $conds['search_query'] = $queryString;

        if ( $objectID !== null )
            $conds['contentobject_id'] = $objectID;

        if ( $languageCode !== null )
            $conds['language_code'] = $languageCode;

        return parent::removeObject( self::definition(), $conds );
    }

    /**
     * Synchronizes the elevate configuration stored in the DB
     * with the one actually used by Solr.
     *
     * @return boolean true if the whole operation passed, false otherwise.
     */
    public static function synchronizeWithSolr()
    {
        if ( self::generateConfiguration() )
        {
            try
            {
                self::pushConfigurationToSolr();
            }
            catch ( Exception $e )
            {
                self::$lastSynchronizationError = $e->getMessage();
                eZDebug::writeError( self::$lastSynchronizationError, 'eZFindElevateConfiguration::synchronizeWithSolr' );
                return false;
            }
        }
        else
        {
            $message = ezi18n( 'extension/ezfind/elevate', "Error while generating the configuration XML" );
            self::$lastSynchronizationError = $message;
            eZDebug::writeError( $message, 'eZFindElevateConfiguration::synchronizeWithSolr' );
            return false;
        }
        return true;
    }

    /**
     * Extracts the configuration stored in the DB and turns it into a Solr-compliant XML string.
     * Stores the result string in the local property $configurationXML
     *
     * @see $configurationXML
     * @return boolean true if the generation run correctly, false otherwise.
     */
    protected static function generateConfiguration()
    {
        $db = eZDB::instance();
        $def = self::definition();
        $query = "SELECT DISTINCT search_query FROM ". $def['name'];
        $limit = 50;
        $offset = 0;
        $querySuffix = " LIMIT $limit OFFSET $offset";
        $solr = new eZSolr();

        $xml = new SimpleXMLElement( self::XML_SKELETON );
        self::$configurationXML = $xml->asXML();

        while( true )
        {
            // fetch distinct search queries
            $rows = $db->arrayQuery( $query . $querySuffix );
            if ( empty( $rows ) )
                break;

            // For each query string, generate the corresponding bloc in elevate.xml
            // Looks like this :
            //
            // <query text="foo bar">
            //    <doc id="1" />
            //    <doc id="2" />
            //    <doc id="3" />
            // </query>
            $xml = new SimpleXMLElement( self::$configurationXML );
            foreach( $rows as $row )
            {
                $searchQuery = $xml->addChild( 'query' );
                $searchQuery->addAttribute( 'text', $row['search_query'] );

                $results = self::fetchObjectsForQueryString( $row['search_query'] );
                foreach( $results as $languageCode => $objects )
                {
                    foreach( $objects as $object )
                    {
                        if ( $languageCode === self::WILDCARD )
                        {
                            $currentVersion = $object->currentVersion();
                            foreach ( $currentVersion->translationList( false, false ) as $lang )
                            {
                                $guid = $solr->guid( $object, $lang );
                                $doc = $searchQuery->addChild( 'doc' );
                                $doc->addAttribute( 'id', $guid );
                            }
                        }
                        else
                        {
                            $guid = $solr->guid( $object, $languageCode );
                            $doc = $searchQuery->addChild( 'doc' );
                            $doc->addAttribute( 'id', $guid );
                        }
                    }
                }
            }

            $offset += $limit;
            $querySuffix = " LIMIT $limit OFFSET $offset";
            self::$configurationXML = $xml->asXML();
        }
        return true;
    }

    /**
     * Simple static getter to the configuration XML
     *
     * @see $configurationXML
     * @return mixed A string containing the configuration XML, null otherwise ( default value of $configurationXML )
     */
    protected static function getConfiguration()
    {
        return self::$configurationXML;
    }

    /**
     * Pushes the configuration XML to Solr through a custom requestHandler ( HTTP/ReST ).
     * The requestHandler ( Solr extension ) will take care of reloading the configuration.
     *
     * @see $configurationXML
     * @return void
     */
    protected static function pushConfigurationToSolr()
    {
        $params = array(
            'qt' => 'ezfind',
            self::CONF_PARAM_NAME => self::getConfiguration()
        );

        $eZSolrBase = new eZSolrBase();
        $result = $eZSolrBase->rawSearch( $params );

        if ( ! $result )
        {
            $message = ezi18n( 'extension/ezfind/elevate', 'An unknown error occured in updating Solr\'s elevate configuration.' );
            eZDebug::writeError( $message, 'eZFindElevateConfiguration::pushConfigurationToSolr' );
            throw new Exception( $message );
        }
        elseif ( isset( $result['error'] ) )
        {
            eZDebug::writeError( $result['error'], 'eZFindElevateConfiguration::pushConfigurationToSolr' );
            throw new Exception( $result['error'] );
        }
        else
        {
            eZDebug::writeNotice( "Successful update of Solr's configuration.", 'eZFindElevateConfiguration::pushConfigurationToSolr' );
        }
    }

    /**
     * Generates a well-formed array of elevate-related query parameters.
     *
     * @param boolean $forceElevation Whether elevation should be forced or not. Parameter supported at runtime from Solr@rev:735117
     *                Should be used when a sort array other than the default one ( 'score desc' ) is passed in the query parameters,
     *                if one wants the elevate configuration to be actually taken into account.
     *
     * @param boolean $enableElevation Whether the Elevate functionnality should be used or not. Defaults to 'true'.
     * @param string $searchText Workaround for an issue in Solr's QueryElevationComponent : when the search string is empty, Solr throws
     *               an Exception and stops the request.
     *
     * @return array The well-formed query parameter regarding the elevate functionnality. Example :
     *         <code>
     *         array( 'forceElevation' => 'true',
     *                'enableElevation' => 'true' )
     *         </code>
     */
    public static function getRuntimeQueryParameters( $forceElevation = false, $enableElevation = true, $searchText = '' )
    {
        $retArray = array( 'forceElevation'  => 'false',
                           'enableElevation' => 'true' );

        if ( $enableElevation === false or $searchText == '' )
        {
            $retArray['enableElevation'] = 'false';
            return $retArray;
        }

        if ( $forceElevation === true )
        {
            $retArray['forceElevation'] = 'true';
        }

        return $retArray;
    }
}
// Initialize the static property containing <eZINI> solr.ini
eZFindElevateConfiguration::$solrINI = eZINI::instance( 'solr.ini' );
?>