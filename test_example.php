#!/usr/bin/php -q
<?php
require_once("class.revgeocode.php");

// START TEST CASE 

// This array can come from anywhere, but this is what the format looks like ...
$conf = array( 
      'debug' => '1',
      'verbose' => '1',
      'use_yahoo' => '1',
      'use_bing' => '1',
      'use_geonames' => '1',
      'use_nominatim' => '1',
      'use_google' => '1',
      'use_google_v3' => '1',
      'sleep_bing' => '2000',
      'sleep_yahoo' => '2000',
      'sleep_google' => '2000',
      'sleep_geonames' => '2000',
      'sleep_nominatim' => '2000',
      'key_geonames' => '[ENTER_GEONAMES_USERNAME_HERE]',
      'key_yahoo' => '[ENTER_YAHOO_KEY_HERE]',
      'key_bing' => '[ENTER_BING_KEY_HERE]',
      'key_google' => '[GOOGLE_MAPS_V2_KEY]',
      'key_nominatim' => '[READ_NOMINATIM_LICENSE_AND_USE_EMAIL_ADDRESS_HERE]',
      'google_premierid' => 'gme-[YOUR_GOOGLE_V3_GME_NAME_HERE]',
      'google_cryptokey' => '[GOOGLE_V3_CRYPTO_KEY]',
      'yahoo_max_fail' => '1',
      'bing_max_fail' => '1',
      'geonames_max_fail' => '1',
      'nominatim_max_fail' => '1',
      'google_max_fail' => '1',
      'cacheservers' => array(
            array('host' => 'localhost:11211', 'name'=> 'slice001', 'type' => 'memcached' ),
            array('host' => 'localhost:11211', 'name'=> 'slice002', 'type' => 'memcached' )
            ),
      'mc_compress' => '1',
      'mc_expire' => '500'
      );

// Create a GeoRev object
$kick_butt=new GeoRev($conf);

// If you set coordinates once, they are used until you pass new ones, either using set_coord like this and get multiple sources
$kick_butt->set_coord(52.223254,5.17502);
$kick_butt->debug(__METHOD__, "simple", 1, sprintf("Nominatim Location = %s",$kick_butt->get_street_name_nominatim()));
$kick_butt->debug(__METHOD__, "simple", 1, sprintf("Google Location    = %s",$kick_butt->get_street_name_google()));
$kick_butt->debug(__METHOD__, "simple", 1, sprintf("Geonames Location  = %s",$kick_butt->get_street_name_geonames()));
$kick_butt->debug(__METHOD__, "simple", 1, sprintf("Bing Location      = %s",$kick_butt->get_street_name_bing()));
$kick_butt->debug(__METHOD__, "simple", 1, sprintf("Yahoo Location     = %s",$kick_butt->get_street_name_yahoo()));

// Or like this directly
$kick_butt->debug(__METHOD__, "simple", 1, sprintf("Yahoo Location     = %s",$kick_butt->get_street_name_yahoo(42.510327,-89.937513)));
$kick_butt->debug(__METHOD__, "simple", 2, $kick_butt->get_counters(),1);

// Try some more: 

// echo $kick_butt->get_street_name_google(52.130365,4.86698333333);
// echo $kick_butt->get_street_name_bing(51.023795,4.539605);
// echo $kick_butt->get_street_name_yahoo(42.510327,-89.937513);
// echo $kick_butt->get_street_name_nominatim(43.821324,-82.923615);
// echo $kick_butt->get_street_name_google(50.974586,4.467527);


// Check our raw cached request (curlinfo + output) per engine:
$kick_butt->debug(__METHOD__, "simple",1, print_r($kick_butt->google_page,true));
$kick_butt->debug(__METHOD__, "simple",1, print_r($kick_butt->yahoo_page,true));

// END TEST CASE 
