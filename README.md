

Introduction
============

PHP-GeoRev-Class is a non-bloated class that makes it easy to use up to 6 supported engines to decode lat/long coordinates to a street name.  It uses JSON all the way internally, so no XML format to throw up over.  This class can be extended to consult any json capable engines for the reverse geocoding information they provide on a lat/lon pair. Optionally uses memcache to cache this information. It's intended use is to sit behind a map that has certain points you want to geocode in advance.  Most providers do not allow you to geocode anything you won't show on their maps and they also don't like you to save this information permanently but they do recommend a caching policy.  Be informed in advance about this.

I made this to compair them in depth on their quality/results.

#### Main features
- Can be extended to extract different data from the resultsets instead of a full streetaddress, or adapted to straight address geocoding.
- Google V2 and V3 supported (Enterprise users)  As an added bonus this supports google V3 Premier ID's too where you need to sign urls.  (so called gme- keys @ google).  The code to sign those is included.
- Handy timing support to throttle back the requests, not by sleeping a fixed amount but by checking how long ago you've hit that particular engine
- MemCached supported
- 6 popular engines supported
- Usage statistics on engines/memcached

#### Reverse geocoding engines
- [Google](http://maps.google.com/maps/api/geocode/json?address=1600+Amphitheatre+Parkway&client=gme-yourclientid&sensor=true&signature=YOUR_URL_SIGNATURE)
- <s>[Yahoo](http://where.yahooapis.com/geocode?q=%1$s,+%2$s&gflags=R&appid=[yourappidhere])</s> Yahoo was to shutdown its services at 17/11/12 but as of today still works
- [Bing](http://dev.virtualearth.net/REST/v1/Locations/50.43434,4.5232323?o=json&key=[key_here])
- [Nominatim](http://open.mapquestapi.com/nominatim/v1/reverse?format=json&json_callback=renderExampleThreeResults&lat=51.521435&lon=-0.162714) By Mapquest Open
- [GeoNames](http://api.geonames.org/findNearbyPlaceNameJSON?lat=%s&lng=%s&username=%s&style=full)
- [Cloudmade](http://geocoding.cloudmade.com/<an_api_key>/geocoding/v2/find.js?object_type=address&around=51.0433583233,4.49876833333&distance=closest)

Api key signup links (incomplete)
--------------------------
- Google Maps API key: http://code.google.com/apis/maps/signup.html
- <s>Yahoo Placefinder Maps API key: http://developer.yahoo.com/maps/simple/</s>
- Nominatim / Mapquest: http://open.mapquestapi.com/nominatim/

Quick-start
===========

1. Get a key for all or any Web Service, if you have none and do not want to wait to try, use your email address for nominatim (read their ULA!) 

2. view the test_example.php, comment out all calls to the engines you do not want to use, adjust the config array with your keys

3. Adjust the debug/verbose values, try 1/2 for more information of what goes on behind the scene.  I took extra effort to put human readable verbose debug statements in there. at level 1/1 it will print out the most basic information.
   
4. Run it !

Usage example
=============
    <?PHP
      require_once("class.revgeocode.php");

      // This array can come from anywhere, but this is what the format looks like ...
      $conf = array( 
            'debug' => '1',
            'verbose' => '1',
            'use_yahoo' => '1',
            'use_bing' => '1',
            'use_geonames' => '1',
            'use_nominatim' => '1',
            'use_google' => '1',
            'use_google_v3' => '0',
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

      // Check our raw cached request (curlinfo + output) per engine:
      $kick_butt->debug(__METHOD__, "simple",1, print_r($kick_butt->google_page,true));

Components
==========

This class uses 2 supporting classes to get things done, "class Latin1UTF8" for transcoding utf8/latin1. You might have to play with inside RevGeo class code to match what you want instead of what I wanted (which was output format latin1 at the time).  The other one is the "class MCache", this is optional, you can use the RevGeo class without it (But I highly suggest you do use memcached). Just don't define any cacheservers in your config array (see above) and it will not be used.

#### Latin1UTF8 class

This is a very simple class, excellent code for cleaning up mixed utf8 strings back and forth from latin1.  I didn not write this myself but found it in search of some solution, I would love to show credit here to the original author but I have no information on who (anymore).  I have used this a lot and this saved me from having to fix a database with mixed records between both encodings.  This little gem is worth a download on itself. (too bad I didn not come up with it myself :).

    <?PHP
      $trans = new Latin1UTF8();
      $mixed = "MIXED TEXT INPUT";

      print "Original: ".$mixed;
      print "Latin1:   ".$trans->mixed_to_latin1($mixed);
      print "UTF-8:    ".$trans->mixed_to_utf8($mixed);

#### MCache class

This class maps all the actions to the optional MemCached server.  This also is not from my hand but I did mod this class to allow me to not get any hard errors on a missing/not working MemCached servers at the __construct phase.  Other than that, this is a pretty raw class that will not handle a running MemCached that starts to fail.  But it works quite fine as long as they are up.  My focus remained supporting the reverse geocode features of each engine but I wanted to show how to create appropriate memcache keys from lat/lon floats.   The original author is named [Grigori Kochanov](http://www.grik.net/). It does its own server pooling.

    <?PHP
      include_once('class.memcached.php');

      $MC = new MCache($memc_servers);

      // Create key/val to store
      $test_val = sprintf("%s%s", md5(rand()),time());
      $test_key = "MCTEST";

      // Set and get 
      $MC->set($test_key, $test_val, $compress=1, $expire=60);
      $result = $MC->get($test_key);

Dependencies
============
 - [cURL for php5](http://php.net/manual/en/book.curl.php): for ubuntu/debian apt-get update && apt-get install php5-curl

Limits
======

There is 1 major method that handles the webservice side of things, which is where cURL is being used, ex: revgeocode_yahoo().  This will store the results of this call along with the curl information about this request in a private class variable $yahoo_page.  On the other side, the output method is is called ex: get_street_name_yahoo().  This is the only method that returns something meaningful.  This is one of the hardest ones to do too.  Adding a function that only returns a country code is a lot easier to build of course.  There is a lot more information accesible under the hood, you just need to write the appropriate formatting method to extract it once it passes the revgeocode methods.  So the limit here is that it only returns -hopefully- human readable addresses.

Mysql functions
===============

In the RevGeo class there are 2 functions to encode/decode lat/lon floats to int format, which you can use as a key, or for fast indexing in a database.  If you want an equivalent of the float2small and -back functions in SQL for MariaDB(MysqlDB) try these:

#### PositionSmallToFloat
    <?SQL
       CREATE DEFINER=`root`@`localhost` FUNCTION `PositionSmallToFloat`(s INT) RETURNS decimal(10,7)
       DETERMINISTIC
       RETURN if( ((s > 0) && (s >> 31)) , (-(0x7FFFFFFF - (s & 0x7FFFFFFF))) / 600000, s / 600000)

#### PositionFloatToSmall
    <?SQL
       CREATE DEFINER=`root`@`localhost` FUNCTION `PositionFloatToSmall`(s DECIMAL(10,7)) RETURNS int(10)
       DETERMINISTIC
       RETURN s * 600000

It's called 'Small' but it really is a large INT

- lat int(10) unsigned NOT NULL
- lon int(10) unsigned NOT NULL

In database terms. The unsigned is important or it doesn't fit in the int(10).  You don't need to install any GIS support for the database, you can use 2 ints as the PK of the table involved, it will be superfast.  Especially if you use partitioning.

Problems running this?
======================
In case you get the following, your PHP is probably not at 5.3 version:

glenn@OBOSQL001:~/glenn$ php -l /home/glenn/glenn/PHP-GeoRev-Class/class.revgeocode.php

Parse error: syntax error, unexpected T_FUNCTION in /home/glenn/glenn/PHP-GeoRev-Class/class.revgeocode.php on line 866

glenn@boboo:~/glenn$ php -v
PHP 5.2.6-1+lenny9 with Suhosin-Patch 0.9.6.2 (cli) (built: Aug  4 2010 03:25:57) 
Copyright (c) 1997-2008 The PHP Group
Zend Engine v2.2.0, Copyright (c) 1998-2008 Zend Technologies

It seems to work here :

glenn@lili:~/PHP-GeoRev-Class$ php -v
PHP 5.3.5-1ubuntu7 with Suhosin-Patch (cli) (built: Apr 17 2011 13:32:40) 
Copyright (c) 1997-2009 The PHP Group
Zend Engine v2.3.0, Copyright (c) 1998-2010 Zend Technologies

Feedback
========

Don't hesitate to submit feedback, bugs and feature requests ! My contact address is glenn at byte-consult dot be or right here.
