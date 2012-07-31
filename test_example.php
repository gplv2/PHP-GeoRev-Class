#!/usr/bin/php -q
<?php
require_once("class.revgeocode.php");

$conf = array( 
      'debug' => '1',
      'verbose' => '5',
      'use_yahoo' => '1',
      'use_bing' => '1',
      'use_geonames' => '1',
      'use_nominatim' => '1',
      'use_google' => '1',
      'use_google_v3' => '0',
      'use_cloudmade' => '1',
      'sleep_bing' => '2000',
      'sleep_yahoo' => '2000',
      'sleep_google' => '2000',
      'sleep_geonames' => '2000',
      'sleep_nominatim' => '2000',
      'sleep_cloudmade' => '2000',
      'key_geonames' => 'key',
      'key_yahoo' => 'key',
      'key_bing' => 'key',
      'key_google' => 'key',
      'key_nominatim' => 'test@byte-consult.be',
      'key_cloudmade' => '',
      'curl_connecttimeout' => 5,
      'curl_connecttimeout_ms' => 5000,
      'google_premierid' => '',
      'google_cryptokey' => '',
      'yahoo_max_fail' => '1',
      'bing_max_fail' => '1',
      'geonames_max_fail' => '1',
      'nominatim_max_fail' => '2',
      'google_max_fail' => '1',
      'cloudmade_max_fail' => '1',
      'cacheservers' => array(
            array('host' => 'localhost:11211', 'name'=> 'slice001', 'type' => 'memcached' ),
            array('host' => 'localhost:11211', 'name'=> 'slice002', 'type' => 'memcached' )
            ),
      'server_urls' => array(
            array('url' => 'http://nominatim.dyndns.org/reversed.php?format=json&lat=%s&lon=%s&zoom=18&addressdetails=1&email=%s&accept-language=nl,en;q=0.8,fr;q=0.5', 'name'=> 'gazzy', 'type' => 'nominatim' , 'state'=> 1, 'last_error'=>''),
            array('url' => 'http://gazzy.dyndns.org:8888/reverse.php?format=json&lat=%s&lon=%s&zoom=18&addressdetails=1&email=%s&accept-language=nl,en;q=0.8,fr;q=0.5', 'name'=> 'gazzy', 'type' => 'nominatim' , 'state'=> 1, 'last_error'=>'')
            ),
      'mc_compress' => '1',
      'mc_expire' => '500',
      'test_memcache' => '0',
      'user_agent_string' => null, /* Will take the default of the class */
      'contact_info' => ""
      );

// Create a GeoRev object
$kick_butt=new GeoRev($conf);

// If you set coordinates once, they are used until you pass new ones, either using set_coord like this and get multiple sources
$kick_butt->set_coord(50.974383,4.467943);
// $kick_butt->debug(__METHOD__, "simple", 1, sprintf("Google Location    = %s",$kick_butt->get_street_name_google()));
// $kick_butt->debug(__METHOD__, "simple", 1, sprintf("Geonames Location  = %s",$kick_butt->get_street_name_geonames()));
// $kick_butt->debug(__METHOD__, "simple", 1, sprintf("Bing Location      = %s",$kick_butt->get_street_name_bing()));
// $kick_butt->debug(__METHOD__, "simple", 1, sprintf("Yahoo Location     = %s",$kick_butt->get_street_name_yahoo()));
// $kick_butt->debug(__METHOD__, "simple", 1, sprintf("Cloudmade Location  = %s",$kick_butt->get_street_name_cloudmade()));
$kick_butt->debug(__METHOD__, "simple", 1, sprintf("The first from any engine that works = %s",print_r($kick_butt->get_street_name_any(null,null,array('nominatim','yahoo')),true)));
$kick_butt->set_coord(51.023795,4.539605);
$kick_butt->debug(__METHOD__, "simple", 1, sprintf("The second from any engine that works = %s",print_r($kick_butt->get_street_name_any(null,null,array('nominatim','yahoo')),true)));
//
/*
$kick_butt->debug(__METHOD__, "simple", 1, sprintf("From all engines = %s",print_r($kick_butt->get_street_name_all(),true)));
$kick_butt->debug(__METHOD__, "simple", 1, sprintf("From all engine = %s",print_r($kick_butt->get_street_name_all(),true)));
Gives me this nice little comparison with 6 engines on:
2012-04-28 18:45:06:[1]- [Main()] (
2012-04-28 18:45:06:[1]- [Main()]     [google] => Damstraat 113-119, 1982 Zemst, BE
2012-04-28 18:45:06:[1]- [Main()]     [yahoo] => Damstraat 117, 1982 Weerde, BE
2012-04-28 18:45:06:[1]- [Main()]     [bing] => Damstraat 117, 1982 Weerde, BE
2012-04-28 18:45:06:[1]- [Main()]     [geonames] => Damstraat (N267)
2012-04-28 18:45:06:[1]- [Main()]     [nominatim] => Damstraat, 1980 Zemst, BE
2012-04-28 18:45:06:[1]- [Main()]     [cloudmade] => Brusselsesteenweg 420, 1980 Eppegem, BE
2012-04-28 18:45:06:[1]- [Main()] )
*/

// Or like this directly
// $kick_butt->debug(__METHOD__, "simple", 1, sprintf("Yahoo Location     = %s",$kick_butt->get_street_name_yahoo(42.510327,-89.937513)));
// $kick_butt->debug(__METHOD__, "simple", 2, $kick_butt->get_counters(),1);

// Try some more: 
// echo $kick_butt->get_street_name_google(52.130365,4.86698333333);
// echo $kick_butt->get_street_name_bing(51.023795,4.539605);
// echo $kick_butt->get_street_name_yahoo(42.510327,-89.937513);
// echo $kick_butt->get_street_name_nominatim(43.821324,-82.923615);
// echo $kick_butt->get_street_name_google(50.974586,4.467527);

// $kick_butt->debug(__METHOD__, "simple",1, print_r($kick_butt->geonames_page,true));
// $kick_butt->debug(__METHOD__, "simple",1, print_r($kick_butt->cloudmade_page,true));
// $kick_butt->debug(__METHOD__, "simple",1, print_r($kick_butt->google_page,true));
// $kick_butt->debug(__METHOD__, "simple",1, print_r($kick_butt->yahoo_page,true));
