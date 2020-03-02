<?php


class BbPiholeApiDb
{
    private $setupVars;

    private $allGetVars;
    private $allPostPutVars;
    private $FTL;

    private $data = array();

    function __construct($request,$args,$setupVars)
    {
        $this->setupVars = $setupVars;
        $this->FTL = new FTL();

        $QUERYDB = SQLiteConn::getQueriesDBFilename();
        #$db = SQLite3_connect($QUERYDB);

        $this->db = SQLiteConn::connect($QUERYDB);

        //$api = true;
        //require("/var/www/html/admin/api_FTL.php");

        //GET
        $this->allGetVars = $request->getQueryParams();
        //POST or PUT
        $this->allPostPutVars = $request->getParsedBody();

        #foreach( $this->allGetVars as $k=>$v ){
        #    $k
        #}
        //$this->FTL->connect("127.0.0.1");

        //if( isset($this->allGetVars['summary'] ) ){
            //    $this->summary();
        //}
        //print_r($this->allPostPutVars);

        //var_dump($this->allGetVars);


    }



    function x (){


        $api = true;
        header('Content-type: application/json');
        require("scripts/pi-hole/php/database.php");
        require("scripts/pi-hole/php/password.php");
        require("scripts/pi-hole/php/auth.php");
        check_cors();

// Set maximum execution time to 10 minutes
        ini_set("max_execution_time","600");

        $data = array();
        $clients = array();
        function resolveHostname($clientip, $printIP)
        {
            global $clients;
            $ipaddr = strtolower($clientip);
            if(array_key_exists($clientip, $clients))
            {
                // Entry already exists
                $clientname = $clients[$ipaddr];
                if($printIP)
                    return $clientname."|".$clientip;
                return $clientname;
            }

            else if(filter_var($clientip, FILTER_VALIDATE_IP))
            {
                // Get host name of client and convert to lower case
                $clientname = strtolower(gethostbyaddr($ipaddr));
            }
            else
            {
                // This is already a host name
                $clientname = $ipaddr;
            }
            // Buffer result
            $clients[$ipaddr] = $clientname;

            if($printIP)
                return $clientname."|".$clientip;
            return $clientname;
        }

// Needs package php5-sqlite, e.g.
//    sudo apt-get install php5-sqlite

        $QUERYDB = getQueriesDBFilename();
        $db = SQLite3_connect($QUERYDB);

        if(isset($_GET["network"]) && $auth)
        {
            $network = array();
            $results = $db->query('SELECT * FROM network');

            while($results !== false && $res = $results->fetchArray(SQLITE3_ASSOC))
            {
                $id = $res["id"];
                // Empty array for holding the IP addresses
                $res["ip"] = array();
                // Get IP addresses for this device
                $network_addresses = $db->query("SELECT ip FROM network_addresses WHERE network_id = $id ORDER BY lastSeen DESC");
                while($network_addresses !== false && $ip = $network_addresses->fetchArray(SQLITE3_ASSOC))
                    array_push($res["ip"],$ip["ip"]);
                // UTF-8 encode host name and vendor
                $res["name"] = utf8_encode($res["name"]);
                $res["macVendor"] = utf8_encode($res["macVendor"]);
                array_push($network, $res);
            }

            $data = array_merge($data, array('network' => $network));
        }

        if (isset($_GET['getAllQueries']) && $auth)
        {
            $allQueries = array();
            if($_GET['getAllQueries'] !== "empty")
            {
                $from = intval($_GET["from"]);
                $until = intval($_GET["until"]);
                $dbquery = "SELECT timestamp, type, domain, client, status FROM queries WHERE timestamp >= :from AND timestamp <= :until ";
                if(isset($_GET["types"]))
                {
                    $types = $_GET["types"];
                    if(preg_match("/^[0-9]+(?:,[0-9]+)*$/", $types) === 1)
                    {
                        // Append selector to DB query. The used regex ensures
                        // that only numbers, separated by commas are accepted
                        // to avoid code injection and other malicious things
                        // We accept only valid lists like "1,2,3"
                        // We reject ",2,3", "1,2," and similar arguments
                        $dbquery .= "AND status IN (".$types.") ";
                    }
                    else
                    {
                        die("Error. Selector types specified using an invalid format.");
                    }
                }
                $dbquery .= "ORDER BY timestamp ASC";
                $stmt = $db->prepare($dbquery);
                $stmt->bindValue(":from", intval($from), SQLITE3_INTEGER);
                $stmt->bindValue(":until", intval($until), SQLITE3_INTEGER);
                $results = $stmt->execute();
                if(!is_bool($results))
                    while ($row = $results->fetchArray())
                    {
                        $c = resolveHostname($row[3],false);

                        // Convert query type ID to name
                        // Names taken from FTL's query type names
                        switch($row[1]) {
                            case 1:
                                $query_type = "A";
                                break;
                            case 2:
                                $query_type = "AAAA";
                                break;
                            case 3:
                                $query_type = "ANY";
                                break;
                            case 4:
                                $query_type = "SRV";
                                break;
                            case 5:
                                $query_type = "SOA";
                                break;
                            case 6:
                                $query_type = "PTR";
                                break;
                            case 7:
                                $query_type = "TXT";
                                break;
                            default:
                                $query_type = "UNKN";
                                break;
                        }
                        // array:        time     type         domain                client           status
                        $allQueries[] = [$row[0], $query_type, utf8_encode($row[2]), utf8_encode($c), $row[4]];
                    }
            }
            $result = array('data' => $allQueries);
            $data = array_merge($data, $result);
        }

        if (isset($_GET['topClients']) && $auth)
        {
            // $from = intval($_GET["from"]);
            $limit = "";
            if(isset($_GET["from"]) && isset($_GET["until"]))
            {
                $limit = "WHERE timestamp >= :from AND timestamp <= :until";
            }
            elseif(isset($_GET["from"]) && !isset($_GET["until"]))
            {
                $limit = "WHERE timestamp >= :from";
            }
            elseif(!isset($_GET["from"]) && isset($_GET["until"]))
            {
                $limit = "WHERE timestamp <= :until";
            }
            $stmt = $db->prepare('SELECT client,count(client) FROM queries '.$limit.' GROUP by client order by count(client) desc limit 20');
            $stmt->bindValue(":from", intval($_GET['from']), SQLITE3_INTEGER);
            $stmt->bindValue(":until", intval($_GET['until']), SQLITE3_INTEGER);
            $results = $stmt->execute();

            $clientnums = array();

            if(!is_bool($results))
                while ($row = $results->fetchArray())
                {
                    // Try to resolve host name and convert to UTF-8
                    $c = utf8_encode(resolveHostname($row[0],false));

                    if(array_key_exists($c, $clientnums))
                    {
                        // Entry already exists, add to it (might appear multiple times due to mixed capitalization in the database)
                        $clientnums[$c] += intval($row[1]);
                    }
                    else
                    {
                        // Entry does not yet exist
                        $clientnums[$c] = intval($row[1]);
                    }
                }

            // Sort by number of hits
            arsort($clientnums);

            // Extract only the first ten entries
            $clientnums = array_slice($clientnums, 0, 10);

            $result = array('top_sources' => $clientnums);
            $data = array_merge($data, $result);
        }

        if (isset($_GET['topDomains']) && $auth)
        {
            $limit = "";

            if(isset($_GET["from"]) && isset($_GET["until"]))
            {
                $limit = " AND timestamp >= :from AND timestamp <= :until";
            }
            elseif(isset($_GET["from"]) && !isset($_GET["until"]))
            {
                $limit = " AND timestamp >= :from";
            }
            elseif(!isset($_GET["from"]) && isset($_GET["until"]))
            {
                $limit = " AND timestamp <= :until";
            }
            $stmt = $db->prepare('SELECT domain,count(domain) FROM queries WHERE (STATUS == 2 OR STATUS == 3)'.$limit.' GROUP by domain order by count(domain) desc limit 20');
            $stmt->bindValue(":from", intval($_GET['from']), SQLITE3_INTEGER);
            $stmt->bindValue(":until", intval($_GET['until']), SQLITE3_INTEGER);
            $results = $stmt->execute();

            $domains = array();

            if(!is_bool($results))
                while ($row = $results->fetchArray())
                {
                    // Convert domain to lower case UTF-8
                    $c = utf8_encode(strtolower($row[0]));
                    if(array_key_exists($c, $domains))
                    {
                        // Entry already exists, add to it (might appear multiple times due to mixed capitalization in the database)
                        $domains[$c] += intval($row[1]);
                    }
                    else
                    {
                        // Entry does not yet exist
                        $domains[$c] = intval($row[1]);
                    }
                }

            // Sort by number of hits
            arsort($domains);

            // Extract only the first ten entries
            $domains = array_slice($domains, 0, 10);

            $result = array('top_domains' => $domains);
            $data = array_merge($data, $result);
        }

        if (isset($_GET['topAds']) && $auth)
        {
            $limit = "";

            if(isset($_GET["from"]) && isset($_GET["until"]))
            {
                $limit = " AND timestamp >= :from AND timestamp <= :until";
            }
            elseif(isset($_GET["from"]) && !isset($_GET["until"]))
            {
                $limit = " AND timestamp >= :from";
            }
            elseif(!isset($_GET["from"]) && isset($_GET["until"]))
            {
                $limit = " AND timestamp <= :until";
            }
            $stmt = $db->prepare('SELECT domain,count(domain) FROM queries WHERE (STATUS == 1 OR STATUS == 4)'.$limit.' GROUP by domain order by count(domain) desc limit 10');
            $stmt->bindValue(":from", intval($_GET['from']), SQLITE3_INTEGER);
            $stmt->bindValue(":until", intval($_GET['until']), SQLITE3_INTEGER);
            $results = $stmt->execute();

            $addomains = array();

            if(!is_bool($results))
                while ($row = $results->fetchArray())
                {
                    $addomains[utf8_encode($row[0])] = intval($row[1]);
                }
            $result = array('top_ads' => $addomains);
            $data = array_merge($data, $result);
        }

        if (isset($_GET['getMinTimestamp']) && $auth)
        {
            $results = $db->query('SELECT MIN(timestamp) FROM queries');

            if(!is_bool($results))
                $result = array('mintimestamp' => $results->fetchArray()[0]);
            else
                $result = array();

            $data = array_merge($data, $result);
        }

        if (isset($_GET['getMaxTimestamp']) && $auth)
        {
            $results = $db->query('SELECT MAX(timestamp) FROM queries');

            if(!is_bool($results))
                $result = array('maxtimestamp' => $results->fetchArray()[0]);
            else
                $result = array();

            $data = array_merge($data, $result);
        }

        if (isset($_GET['getQueriesCount']) && $auth)
        {
            $results = $db->query('SELECT COUNT(timestamp) FROM queries');

            if(!is_bool($results))
                $result = array('count' => $results->fetchArray()[0]);
            else
                $result = array();

            $data = array_merge($data, $result);
        }

        if (isset($_GET['getDBfilesize']) && $auth)
        {
            $filesize = filesize("/etc/pihole/pihole-FTL.db");
            $result = array('filesize' => $filesize);
            $data = array_merge($data, $result);
        }

        if (isset($_GET['getGraphData']) && $auth)
        {
            $limit = "";

            if(isset($_GET["from"]) && isset($_GET["until"]))
            {
                $limit = " AND timestamp >= :from AND timestamp <= :until";
            }
            elseif(isset($_GET["from"]) && !isset($_GET["until"]))
            {
                $limit = " AND timestamp >= :from";
            }
            elseif(!isset($_GET["from"]) && isset($_GET["until"]))
            {
                $limit = " AND timestamp <= :until";
            }

            $interval = 600;

            if(isset($_GET["interval"]))
            {
                $q = intval($_GET["interval"]);
                if($q > 10)
                    $interval = $q;
            }

            // Round $from and $until to match the requested $interval
            $from = intval((intval($_GET['from'])/$interval)*$interval);
            $until = intval((intval($_GET['until'])/$interval)*$interval);

            // Count permitted queries in intervals
            $stmt = $db->prepare('SELECT (timestamp/:interval)*:interval interval, COUNT(*) FROM queries WHERE (status != 0 )'.$limit.' GROUP by interval ORDER by interval');
            $stmt->bindValue(":from", $from, SQLITE3_INTEGER);
            $stmt->bindValue(":until", $until, SQLITE3_INTEGER);
            $stmt->bindValue(":interval", $interval, SQLITE3_INTEGER);
            $results = $stmt->execute();

            // Parse the DB result into graph data, filling in missing interval sections with zero
            function parseDBData($results, $interval, $from, $until) {
                $data = array();

                if(!is_bool($results)) {
                    // Read in the data
                    while($row = $results->fetchArray()) {
                        // $data[timestamp] = value_in_this_interval
                        $data[$row[0]] = intval($row[1]);
                    }
                }

                return $data;
            }

            $domains = parseDBData($results, $interval, $from, $until);

            $result = array('domains_over_time' => $domains);
            $data = array_merge($data, $result);

            // Count blocked queries in intervals
            $stmt = $db->prepare('SELECT (timestamp/:interval)*:interval interval, COUNT(*) FROM queries WHERE (status == 1 OR status == 4 OR status == 5)'.$limit.' GROUP by interval ORDER by interval');
            $stmt->bindValue(":from", $from, SQLITE3_INTEGER);
            $stmt->bindValue(":until", $until, SQLITE3_INTEGER);
            $stmt->bindValue(":interval", $interval, SQLITE3_INTEGER);
            $results = $stmt->execute();

            $addomains = parseDBData($results, $interval, $from, $until);

            $result = array('ads_over_time' => $addomains);
            $data = array_merge($data, $result);
        }

        if(isset($_GET["jsonForceObject"]))
        {
            echo json_encode($data, JSON_FORCE_OBJECT);
        }
        else
        {
            echo json_encode($data);
        }



    }









    function getAllQueries(){

        if(isset($this->allGetVars['from']) && isset($this->allGetVars['until']))
        {
            // Get limited time interval
            $this->FTL->sendrequest("getallqueries-time ".$this->allGetVars['from']." ".$this->allGetVars['until']);
        }
        else if(isset($this->allGetVars['domain']))
        {
            // Get specific domain only
            $this->FTL->sendrequest("getallqueries-domain ".$this->allGetVars['domain']);
        }
        else if(isset($this->allGetVars['client']))
        {
            // Get specific client only
            $this->FTL->sendrequest("getallqueries-client ".$this->allGetVars['client']);
        }
        else if(isset($this->allGetVars['querytype']))
        {
            // Get specific query type only
            $this->FTL->sendrequest("getallqueries-qtype ".$this->allGetVars['querytype']);
        }
        else if(isset($this->allGetVars['forwarddest']))
        {
            // Get specific forward destination only
            $this->FTL->sendrequest("getallqueries-forward ".$this->allGetVars['forwarddest']);
        }
        else if(is_numeric($this->allGetVars['getAllQueries']))
        {
            echo "getAllQueries";
            $this->FTL->sendrequest("getallqueries (".$this->allGetVars['getAllQueries'].")");
        }
        else
        {
            // Get all queries
            $this->FTL->sendrequest("getallqueries");
        }


        $allQueries = array();
        if($this->allGetVars['getAllQueries'] !== "empty")
        {
            echo "getAllQueries 2";

            $from = intval($this->allGetVars["from"]);
            $until = intval($this->allGetVars["until"]);

            $dbquery = "SELECT timestamp, type, domain, client, status FROM queries WHERE timestamp >= :from AND timestamp <= :until ";
            if(isset($this->allGetVars["types"]))
            {
                $types = $this->allGetVars["types"];
                if(preg_match("/^[0-9]+(?:,[0-9]+)*$/", $types) === 1)
                {
                    // Append selector to DB query. The used regex ensures
                    // that only numbers, separated by commas are accepted
                    // to avoid code injection and other malicious things
                    // We accept only valid lists like "1,2,3"
                    // We reject ",2,3", "1,2," and similar arguments
                    $dbquery .= "AND status IN (".$types.") ";
                }
                else
                {
                    die("Error. Selector types specified using an invalid format.");
                }
            }
            $dbquery .= "ORDER BY timestamp ASC";
            $stmt = $this->db->prepare($dbquery);

            //echo $dbquery;

            $stmt->bindValue(":from", intval($from), SQLITE3_INTEGER);
            $stmt->bindValue(":until", intval($until), SQLITE3_INTEGER);

            $results = $stmt->execute();
            //echo "----------";
            //var_dump($results->fetchArray());
            //echo "----------";

            if(!is_bool($results)) {
                while ($row = $results->fetchArray()) {

                    $c = $this->resolveHostname($row[3], false);

                    // Convert query type ID to name
                    // Names taken from FTL's query type names
                    switch ($row[1]) {
                        case 1:
                            $query_type = "A";
                            break;
                        case 2:
                            $query_type = "AAAA";
                            break;
                        case 3:
                            $query_type = "ANY";
                            break;
                        case 4:
                            $query_type = "SRV";
                            break;
                        case 5:
                            $query_type = "SOA";
                            break;
                        case 6:
                            $query_type = "PTR";
                            break;
                        case 7:
                            $query_type = "TXT";
                            break;
                        default:
                            $query_type = "UNKN";
                            break;
                    }
                    // array:        time     type         domain                client           status
                    $allQueries[] = [$row[0], $query_type, utf8_encode($row[2]), utf8_encode($c), $row[4]];
                }
            }
        }else{
            #echo "PPOOOPOOO";
        }
        $result = array('data' => $allQueries);

        var_dump($result);
#        var_dump($this->allGetVars['getAllQueries']);


        $this->setData($result);
        //$data = array_merge($data, $result);
    }


    function resolveHostname($clientip, $printIP)
    {
        global $clients;
        $ipaddr = strtolower($clientip);
        if(array_key_exists($clientip, $clients))
        {
            // Entry already exists
            $clientname = $clients[$ipaddr];
            if($printIP)
                return $clientname."|".$clientip;
            return $clientname;
        }

        else if(filter_var($clientip, FILTER_VALIDATE_IP))
        {
            // Get host name of client and convert to lower case
            $clientname = strtolower(gethostbyaddr($ipaddr));
        }
        else
        {
            // This is already a host name
            $clientname = $ipaddr;
        }
        // Buffer result
        $clients[$ipaddr] = $clientname;

        if($printIP)
            return $clientname."|".$clientip;
        return $clientname;
    }


    function network (){
        $network = array();
        $results = $this->db->query('SELECT * FROM network');
        //print_r($results);
        while($results !== false && $res = $results->fetchArray(SQLITE3_ASSOC))
        {
            $id = $res["id"];
            // Empty array for holding the IP addresses
            $res["ip"] = array();
            // Get IP addresses for this device
            $network_addresses = $this->db->query("SELECT ip FROM network_addresses WHERE network_id = $id ORDER BY lastSeen DESC");
            while($network_addresses !== false && $ip = $network_addresses->fetchArray(SQLITE3_ASSOC))
                array_push($res["ip"],$ip["ip"]);
            // UTF-8 encode host name and vendor
            $res["name"] = utf8_encode($res["name"]);
            $res["macVendor"] = utf8_encode($res["macVendor"]);
            array_push($network, $res);
        }

        $this->setData(array('network' => $network));
        //$data = array_merge($data, array('network' => $network));

    }







    private function setData($theData){
        $data = array_merge($this->data,$theData);
        $this->data = $data;
    }

    function result(){
        return $this->data;
    }






















}
