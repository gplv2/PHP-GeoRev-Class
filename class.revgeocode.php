<?php
/**
 * GeoRev class
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * For questions, help, comments, discussion, etc., 
 *
 * @link http://byte-consult.be/
 * @version 1.0.0
 * @copyright Copyright: 2011 Glenn Plas
 * @author   Glenn Plas <glenn AT byte-consult.be>
 * @access public
 */
require_once('utf8_helper.php');

/*
 * Uses the following web based json capable geocoding engines links, supports google V3 and V2 with premier id keys' (gme-[your_key])
 * http://maps.google.com/maps/api/geocode/json?address=1600+Amphitheatre+Parkway&client=gme-yourclientid&sensor=true&signature=YOUR_URL_SIGNATURE
 * http://where.yahooapis.com/geocode?q=%1$s,+%2$s&gflags=R&appid=[yourappidhere]
 * http://dev.virtualearth.net/REST/v1/Locations/50.43434,4.5232323?o=json&key=[key_here]
 * http://open.mapquestapi.com/nominatim/v1/reverse?format=json&json_callback=renderExampleThreeResults&lat=51.521435&lon=-0.162714
 * http://api.geonames.org/findNearbyPlaceNameJSON?lat=%s&lng=%s&username=%s&style=full
 * http://geocoding.cloudmade.com/<an_api_key>/geocoding/v2/find.js?object_type=address&around=51.0433583233,4.49876833333&distance=closest
 */

Class GeoRev {

   // Defaults, base stuff we can't do without
   private $settings= array( 
         'debug' => 0,
         'verbose' => 0,
         'use_yahoo' => 0,
         'use_bing' => 0,
         'use_geonames' => 0,
         'use_nominatim' => 0,
         'use_google' => 0,
         'use_google_v3' => 0,
         'use_yandex' => 0,
         'use_cloudmade' => 0,
         'sleep_bing' => '5000',
         'sleep_yahoo' => '5000',
         'sleep_google' => '5000',
         'sleep_geonames' => '5000',
         'sleep_nominatim' => '5000',
         // Note yandex doesn't work too wel atm ...
         'sleep_yandex' => '5000',
         'sleep_cloudmade' => '5000',
         'mc_compress' => 1,
         'mc_compress' => 1,
         'user_agent_string' => 'php-lib ( https://github.com/gplv2/PHP-GeoRev-Class )',
         'mc_expire' => 500
         );


   // This kinda maps what belongs to who
   private $service_variable_map = array (
         'bing' => array('timer_name' => 'bi_timer','sleep_setting' => 'sleep_bing', 'page'=> 'bing_page'),
         'yahoo' => array('timer_name' => 'ya_timer','sleep_setting' => 'sleep_yahoo', 'page'=> 'yahoo_page'),
         'google' => array('timer_name' => 'go_timer','sleep_setting' => 'sleep_google', 'page'=> 'google_page'),
         'geonames' => array('timer_name' => 'ge_timer','sleep_setting' => 'sleep_geonames', 'page'=> 'geonames_page'),
         'nominatim' => array('timer_name' => 'no_timer','sleep_setting' => 'sleep_nominatim', 'page'=> 'nominatim_page'),
         // 'yandex' => array('timer_name' => 'gp_timer','sleep_setting' => 'sleep_yandex', 'page'=> 'yandex_page'),
         'cloudmade' => array('timer_name' => 'cm_timer','sleep_setting' => 'sleep_cloudmade', 'page'=> 'cloudmade_page')
         );

   // Runtime helper vars
   private $eol;
   private $trans;
   private $MC;
   // private $o_enc="utf8"; # output_encode "utf8" or "latin1" # not used 

   // The most important ones
   private $lat;
   private $lon;

   // Runtime stats
   private $counters=array();

   // Raw return cache of the page, its' ok these are public for the example, they should be private really
   public $google_page; 
   public $yahoo_page; 
   public $bing_page; 
   public $geonames_page; 
   public $nominatim_page; 
   public $yandex_page; 
   public $cloudmade_page; 

   // My own public postal code service AS IS for the time being (Belgium only, I'm redesigning this to fit postgis geometry types
   private $gazzy_page; 

   // Dev aid
   public $debug;
   public $verbose;

   public function __construct($conf_settings) {
      /* My own test if we are called from the CLI , could use PHP_EOL instead I know */
      if (defined('STDIN')) {
         $this->eol="\n";
      } else {
         $this->eol="<BR/>";
      }

      if (!isset($conf_settings)) {
	      echo __METHOD__ . ": config error\n";
      exit(0);
	   }

      /* Prepare this one already */
      $this->trans= new Latin1UTF8();

      /* check for curl */
      if (!function_exists('curl_init')) {
         throw new Exception(sprintf("cURL has to be installed in order to get %s class to work.",__CLASS__));
      }

      /* Determine the state of the engines from the settings */
      $auto_settings['can_use_google_v3'] = (!empty($conf_settings['google_premierid']) and !empty($conf_settings['use_google']) ) ? 1 : 0;
      $auto_settings['can_use_google']    = !empty($conf_settings['use_google'])       ? 1 : 0;
      $auto_settings['can_use_yahoo']     = !empty($conf_settings['key_yahoo'])        ? 1 : 0;
      $auto_settings['can_use_bing']      = !empty($conf_settings['key_bing'])         ? 1 : 0;
      $auto_settings['can_use_geonames']  = !empty($conf_settings['key_geonames'])     ? 1 : 0;
      $auto_settings['can_use_nominatim'] = !empty($conf_settings['key_nominatim'])    ? 1 : 0;
      $auto_settings['can_use_yandex'] = !empty($conf_settings['key_yandex'])    ? 1 : 0;
      $auto_settings['can_use_cloudmade'] = !empty($conf_settings['key_cloudmade'])    ? 1 : 0;

      // Merge the class defaults with the settings
      $this->settings = array_merge($this->settings, $conf_settings);

      // Merge the autosettings with the settings
      $this->settings = array_merge($this->settings, $auto_settings);

      // $this->debug(__METHOD__, "simple" , 1, "",1);

      // Set the correct debug values
      $this->verbose=$this->settings['verbose'];
      $this->debug=$this->settings['debug'];

      // $this->debug(__METHOD__, "simple" , 1, sprintf("%d / %d",$this->debug,$this->verbose));

      $this->debug(__METHOD__, "simple" , 1, sprintf("Status geocode engines"));
      $this->debug(__METHOD__, "simple" , 1, $auto_settings,1);

      $this->debug(__METHOD__, "simple" , 1, $this->settings,1);

      $this->count_engines_available();
/*
      // Check for engine availability right off the bat before going further, we can't do anything meaningfull without atleast 1
      if (!$this->count_engines_available()) {
         $this->debug(__METHOD__, "simple", 0, sprintf("No geocoding engine available, check config file for key / parameter settings"));
         exit;
      }
*/
      /* Analyse config settings for memcached servers */
      if (isset($conf_settings['cacheservers']) and is_array($conf_settings['cacheservers'])) {
         // We have some settings, lets try to see if they work.
         $memc_servers = array();

         foreach($conf_settings['cacheservers'] as $cache_server){
            if (strcmp($cache_server['type'], "memcached")==0) {
               $this->debug(__METHOD__, "simple" , 2, sprintf("Adding %s memcache host",$cache_server['host']));
               $memc_servers[] = $cache_server;
            } else {
               $this->debug(__METHOD__, "simple" , 2, sprintf("Ignoring %s cache host",$cache_server['host']));
            }
         }

         if(count($memc_servers)>0) {
            include_once('class.memcached.php');
            $this->MC = new MCache($memc_servers);
            $memcached_counters= array ('memc_get','memc_set','memc_hit','memc_miss');
            $this->register_counter($memcached_counters,0);
            // var_dump($this->MC);
            if (!$this->MC->getServerCount()) {
               $this->debug(__METHOD__, "simple" , 2, sprintf("No Memcache servers candidates defined"));
               unset($this->MC);
            } else {
               $this->debug(__METHOD__, "simple" , 2, sprintf("%d Memcache servers defined ...",$this->MC->getServerCount()));
               if (!empty($conf_settings['test_memcache'])) {
                  $this->debug(__METHOD__, "simple" , 2, sprintf("Testing Memcache ..."));

                  // Create test data
                  $test_val = sprintf("%s%s", md5(json_encode($conf_settings['cacheservers'])),time());
                  $test_key = "GEOREVTEST";

                  // Set and get a known value
                  $this->MC->set($test_key, $test_val, $compress=1, $expire=60);
                  $result = $this->MC->get($test_key);

                  if (strcmp($result, $test_val) == 0) {
                     $this->debug(__METHOD__, "simple" , 2, sprintf("Memcache seems to be working."));
                  } else {
                     // Memcached doesn't seem to work
                     $this->debug(__METHOD__, "simple" , 2, sprintf("Disabling Memcache since its not working."));
                     unset($this->MC);
                  }
               }
            }
         }
      }

      // Get some counters registered and initialised 
      $init_counters = array ('hit_google',
            'hit_yahoo',
            'hit_geonames',
            'hit_bing',
            'hit_nominatim',
            'hit_yandex',
            'hit_gazzy',
            'hit_cloudmade',
            'fail_yahoo',
            'fail_bing',
            'fail_geonames',
            'fail_google',
            'fail_nominatim',
            'fail_yandex',
            'fail_cloudmade',
            'fail_gazzy',
            'ok_bing',
            'ok_google',
            'ok_yahoo',
            'ok_geonames',
            'ok_yandex',
            'ok_nominatim',
            'ok_cloudmade',
            'ok_gazzy'
            );
      $this->register_counter($init_counters,0);

      // Register the timers for all services (used to be a bit more hardcoded)
      foreach ($this->service_variable_map as $engine => $service ) {
         // timers are = array ('go_timer','ya_timer','bi_timer','ge_timer','no_timer','gp_timer','cm_timer');
         if ($this->get_engines_available($engine)) {
            $sleep_service = $service['sleep_setting'];
            $minimum_sleep = $sleep_service*1000;
            $timer_name = $service['timer_name'];
            $this->debug( __METHOD__, "simple", 3, sprintf("Registering timer -> %s",$engine));
            // We have to substract the delay when setting the start value
            $this->register_counter($timer_name, (microtime(true) - $minimum_sleep));
         }
      }
      // $this->debug(__METHOD__, "simple", 1, $this->counters);
   }

   private function register_counter($name="dummy",$default_var=0) {
      $this->debug(__METHOD__, "call",5);
      if (!isset($default_var)) {
         $default_var=0;
      }

      if (!is_array($name)) {
         $name=(array)$name;
      }

      foreach($name as $i => $counter_name) {
         if (strlen($counter_name) > 0) {
            $this->debug(__METHOD__, "simple", 4, sprintf("Initializing %s = %s",$counter_name,$default_var));
            $this->counters[$counter_name]=$default_var;
         }
      }
   }

   public function get_counters() {
      return $this->counters;
   }

   public function set_coord($lat,$lon) {
      $this->debug(__METHOD__, "call",5);
      $retval=1;
      $status="Coordinates ok";

      if (!isset($lat) OR !isset($lon)) {
         $retval= -1;
         $status="Need atleast lat/lon pair to do meaningful things";
      }
      if(($lat >= -90) AND ($lat <= 90 )) {
         $this->lat=$lat;
      } else {
         $retval= 0;
         $status="Latitude outside boundaries";
      } 
      if(($lon >= -180) AND ($lon <= 180 )) {
         $this->lon=$lon;
      } else {
         $retval= 0;
         $status="Longitude outside boundaries";
      }
      $this->debug(__METHOD__, "call",sprintf("code = %d ( %s )",$retval,$status));
      //$ret = array ('code' => $retval,  'status' => $status);
      $this->debug(__METHOD__, "hangup",5);
      return $retval;
   }

// GLENN
   private function revgeocode_yandex() {
      $this->debug(__METHOD__, "call",5);
      $tag='yandex';

      // The memcache key , trying to keep it somewhat short
      $mckey=sprintf("RG%s_%s|%s",strtoupper(substr($tag,0,4)), $this->float_to_small($this->lat), $this->float_to_small($this->lon));

      // Fetch from cache if we can 
      if(isset($this->MC)){
         $this->debug(__METHOD__, "simple",3,"Checking cache...");
         $this->debug(__METHOD__, "simple",3,sprintf("Memcache key %s",$mckey));
         $cached = $this->MC->get($mckey);
         $this->counters['memc_get']++;
         $page = json_decode($cached['contents'],true);
         if (is_array($page)) {
            $this->debug(__METHOD__, "simple",3,"Cached data found.");
            // Seems we have some valid cache data for this service
            $this->yandex_page=$cached;
            $this->counters['memc_hit']++;
            return 1;
         } else {
            $this->counters['memc_miss']++;
         }
      }

      if($this->counters['fail_yandex']>$this->settings['yandex_max_fail'] OR !$this->settings['can_use_yandex']) {
         $this->settings['use_yandex']=0;
         return "";
      }

      if (!empty($this->settings['dryrun'])) {
         $this->debug(__METHOD__, "simple",5,"Dryrun active, not doing encode now.");
         return "";
      }

      if(empty($this->settings['use_yandex'])) {
         $this->debug(__METHOD__, "simple",0,sprintf("Engine %s is disabled",$tag));
         return "";
      }
      // Delay as specified
      $this->throttle_service($tag);
      $this->debug( __METHOD__, "simple", 2, sprintf("Encoding with yandex"));

      /* ll=
         :geocode => query,
         :format => "json",
         :plng => "#{Geocoder::Configuration.language}", # supports ru, uk, be
         :key => Geocoder::Configuration.api_key
      */
      $baseurl = "http://geocode-maps.yandex.ru/1.x/?geocode=%s&format=json&plng=uk&key=%s";

      $url = sprintf($baseurl, $this->lat, $this->lon);
      // $url = sprintf($baseurl, $this->lat, $this->lon, $this->settings['key_yandex']);

      $this->debug( __METHOD__, "simple", 2, sprintf("Geocoding url '%s'", $url));
      $this->counters['hit_yandex']++;

      /* Do it with curl */
      $ch = curl_init($url);

      // set user agent
      curl_setopt($ch, CURLOPT_USERAGENT, $this->settings['user_agent_string']);

      curl_setopt($ch, CURLOPT_RETURNTRANSFER , true);
      $server_output = curl_exec($ch);
      $curlinfo = curl_getinfo($ch);
      curl_close($ch);

      $this->debug( __METHOD__, "simple", 5, $curlinfo,1);

      if ($curlinfo['http_code']!=200) {
         $this->counters['fail_yandex']++;
         return "";
      }

      // $this->debug( __METHOD__, "simple", 5, $server_output);

      // "text/javascript; charset=utf-8"
      $contents = $server_output; 
      if (preg_match("/utf-8/", strtolower($curlinfo['content_type']), $matches)) {
         if (!empty($matches[0])) {
            $this->debug( __METHOD__, "simple", 2, "Encoding utf-8");
            $contents = utf8_encode($server_output); 
         }
      }

      $this->yandex_page = array ('curlinfo' => $curlinfo, 'contents' => $contents);
      // Store in the cache if we can
      if(isset($this->MC)){
         $this->debug(__METHOD__, "simple",2,"Saving in cache");
         $this->MC->set($mckey, $this->yandex_page, $this->settings['mc_compress'], $this->settings['mc_expire']);
         $this->counters['memc_set']++;
      }
      $this->counters['gp_timer']=microtime(true);
      return 1;
   }

   public function getpostcodeinfo($postcode){
      $tag='gzzy';

      // The memcache key
      $mckey=sprintf("ZZ%s_%s|%s",strtoupper(substr($tag,0,4)), $this->float_to_small($this->lat), $this->float_to_small($this->lon));

      $this->debug(__METHOD__, "call",5);
      //$url="http://gazzy.byte-consult.be/postcode.php?format=json&postcode=7100&accept-language=nl,en;q=0.8,fr;q=0.5";
      $url=sprintf("http://gazzy.byte-consult.be/postcode.php?format=json&postcode=%d&accept-language=nl,en;q=0.8,fr;q=0.5",$postcode);
      $this->debug( __METHOD__, "simple", 2, sprintf("Requesting postcode information from url '%s'", $url));
      $this->counters['hit_gazzy']++;

      /* Do it with curl */
      $ch = curl_init($url);

      // set user agent
      curl_setopt($ch, CURLOPT_USERAGENT, $this->settings['user_agent_string']);

      curl_setopt($ch, CURLOPT_RETURNTRANSFER , true);
      $server_output = curl_exec($ch);
      $curlinfo = curl_getinfo($ch);
      curl_close($ch);

      $this->debug( __METHOD__, "simple", 5, $curlinfo,1);

      if ($curlinfo['http_code']!=200) {
         $this->counters['fail_gazzy']++;
         return "";
      }
      $this->counters['ok_gazzy']++;

      // $this->debug( __METHOD__, "simple", 5, $server_output);

      // "text/javascript; charset=utf-8"
      $contents = $server_output; 
      if (preg_match("/utf-8/", strtolower($curlinfo['content_type']), $matches)) {
         if (!empty($matches[0])) {
            $this->debug( __METHOD__, "simple", 2, "Encoding utf-8");
            $contents = utf8_encode($server_output); 
         }
      }

      $this->gazzy_page = array ('curlinfo' => $curlinfo, 'contents' => $contents);
      // Store in the cache if we can
      if(isset($this->MC)){
         $this->debug(__METHOD__, "simple",2,"Saving in cache");
         $this->MC->set($mckey, $this->gazzy_page, $this->settings['mc_compress'], $this->settings['mc_expire']);
         $this->counters['memc_set']++;
      }
      return($contents);
   }

   private function revgeocode_google () {
      $this->debug(__METHOD__, "call",5);
      $tag='google';

      // The memcache key
      $mckey=sprintf("RG%s_%s|%s",strtoupper(substr($tag,0,4)), $this->float_to_small($this->lat), $this->float_to_small($this->lon));

      // Fetch from cache if we can 
      if(isset($this->MC)){
         $this->debug(__METHOD__, "simple",3,"Checking cache...");
         $this->debug(__METHOD__, "simple",3,sprintf("Memcache key %s",$mckey));
         $cached = $this->MC->get($mckey);
         $this->counters['memc_get']++;
         $page = json_decode($cached['contents'],true);
         if (is_array($page)) {
            $this->debug(__METHOD__, "simple",3,"Cached data found.");
            // Seems we have some valid cache data for this service
            $this->google_page=$cached;
            $this->counters['memc_hit']++;
            return 1;
         } else {
            $this->counters['memc_miss']++;
         }
      }

      if($this->counters['fail_google']>$this->settings['google_max_fail'] OR (!$this->settings['can_use_google'] AND !$this->settings['can_use_google_v3'])) {
         $this->settings['use_google']=0;
         return "";
      }

      if (!empty($this->settings['dryrun'])) {
         $this->debug(__METHOD__, "simple",5,"Dryrun active, not doing encode now.");
         return "";
      }

      if(empty($this->settings['use_google'])) {
         $this->debug(__METHOD__, "simple",0,sprintf("Engine %s is disabled",$tag));
         return "";
      }

      // Delay as specified
      $this->throttle_service($tag);
      $this->debug( __METHOD__, "simple", 2, sprintf("Encoding with Google maps"));

      $baseurl = "http://maps.google.com";

      if(!empty($this->settings['use_google_v3'])) {
         // We need to url sign this request, like this example: 
         // http://maps.google.com/maps/api/geocode/json?address=1600+Amphitheatre+Parkway&client=gme-yourclientid&sensor=false&signature=YOUR_URL_SIGNATURE
         $signurl = sprintf("/maps/geo?q=%s,%s&client=%s&output=json&sensor=false", $this->lat, $this->lon, $this->settings['google_premierid']);
         $signature=$this->sign_url($baseurl . $signurl);
         $url=$baseurl . $signurl . "&signature=" . $signature;
         $this->debug(__METHOD__, "simple" , 2, sprintf("Google v3"));
      } else {
         $url = sprintf("%s/maps/geo?q=%s,%s&output=json&key=%s&sensor=false", $baseurl, $this->lat, $this->lon, $this->settings['key_google']);
         $this->debug(__METHOD__, "simple" , 5, sprintf("Google v2"));
      }

      $this->debug( __METHOD__, "simple", 2, sprintf("Geocoding url '%s'", $url));
      $this->counters['hit_google']++;

      /* Do it with curl */
      $ch = curl_init($url);

      // set user agent
      curl_setopt($ch, CURLOPT_USERAGENT, $this->settings['user_agent_string']);

      curl_setopt($ch, CURLOPT_RETURNTRANSFER , true);
      $server_output = curl_exec($ch);
      $curlinfo = curl_getinfo($ch);
      curl_close($ch);

      $this->debug( __METHOD__, "simple", 5, $curlinfo,1);

      if ($curlinfo['http_code']!=200) {
         $this->counters['fail_google']++;
         return "";
      }

      // $this->debug( __METHOD__, "simple", 5, $server_output);

      // "text/javascript; charset=utf-8"
      $contents = $server_output; 
      if (preg_match("/utf-8/", strtolower($curlinfo['content_type']), $matches)) {
         if (!empty($matches[0])) {
            $this->debug( __METHOD__, "simple", 2, "Encoding utf-8");
            $contents = utf8_encode($server_output); 
         }
      }

      $this->google_page = array ('curlinfo' => $curlinfo, 'contents' => $contents);
      // Store in the cache if we can
      if(isset($this->MC)){
         $this->debug(__METHOD__, "simple",2,"Saving in cache");
         $this->MC->set($mckey, $this->google_page, $this->settings['mc_compress'], $this->settings['mc_expire']);
         $this->counters['memc_set']++;
      }
      $this->counters['go_timer']=microtime(true);
      return 1;
   }


   private function revgeocode_bing () {
      $this->debug(__METHOD__, "call",5);
      $tag='bing';

      // The memcache key
      $mckey=sprintf("RG%s_%s|%s",strtoupper(substr($tag,0,4)), $this->float_to_small($this->lat), $this->float_to_small($this->lon));

      // Fetch from cache if we can 
      if(isset($this->MC)){
         $this->debug(__METHOD__, "simple",3,"Checking cache...");
         $this->debug(__METHOD__, "simple",3,sprintf("Memcache key %s",$mckey));
         $cached = $this->MC->get($mckey);
         $this->counters['memc_get']++;
         $page = json_decode($cached['contents'],true);
         if (is_array($page)) {
            $this->debug(__METHOD__, "simple",3,"Cached data found.");
            // Seems we have some valid cache data for this service
            $this->bing_page=$cached;
            $this->counters['memc_hit']++;
            return 1;
         } else {
            $this->counters['memc_miss']++;
         }
      }

      if($this->counters['fail_bing']>$this->settings['bing_max_fail'] OR !$this->settings['can_use_bing']) {
         $this->settings['use_bing']=0;
         return "";
      }

      if (!empty($this->settings['dryrun'])) {
         $this->debug(__METHOD__, "simple",5,"Dryrun active, not doing encode now.");
         return "";
      }

      /* Bing license programs
         Enterprise (formerly Commercial): This option is available to licensed commercial accounts only.
Developer: Application does not exceed 125,000 sessions or 500,000 transactions within a 12 month period.
Evaluation/Trial: Application is used for public or internal use during a 90 day evaluation period.
Broadcast: Application is used for public or internal-facing television, movies or similar.
Mobile: Application is used for publically available and installable applications on mobile handsets.
Education: Application is used for public use by schools, including faculty, staff and students.
Not-for-profit: Application is used by a tax-exempt organization.
       */
      if(empty($this->settings['use_bing'])) {
         $this->debug(__METHOD__, "simple",0,sprintf("Engine %s is disabled",$tag));
         return "";
      }

      $this->throttle_service($tag);
      $this->debug( __METHOD__, "simple", 2, sprintf("Encoding with Bing"));

      $baseurl = "http://dev.virtualearth.net/REST/v1/Locations/%s,%s?o=json&key=%s";
      $url = sprintf($baseurl,$this->lat,$this->lon,$this->settings['key_bing']);

      $this->debug( __METHOD__, "simple", 2, sprintf("Geocoding url '%s'", $url));
      $this->counters['hit_bing']++;

      /* Do it with curl */
      $ch = curl_init($url);

      // set user agent
      curl_setopt($ch, CURLOPT_USERAGENT, $this->settings['user_agent_string']);

      curl_setopt($ch, CURLOPT_RETURNTRANSFER , true);
      $server_output = curl_exec($ch);
      $curlinfo = curl_getinfo($ch);
      curl_close($ch);

      $this->debug( __METHOD__, "simple", 5, $curlinfo,1);

      /*
         [] content_type  = application/json
         [] http_code     = 400
         [] header_size   = 335
         [] request_size  = 154
         [] filetime      = -1
         [] ssl_verify_result = 0
         [] redirect_count = 0
         [] total_time    = 0.493183
         [] namelookup_time = 0.479224
         [] connect_time  = 0.480385
         [] pretransfer_time = 0.480393
         [] size_upload   = 0
         [] size_download = 589
         [] speed_download = 1194
         [] speed_upload  = 0
         [] download_content_length = -1
         [] upload_content_length = 0
         [] starttransfer_time = 0.490021
         [] redirect_time = 0
       */

      if ($curlinfo['http_code']!=200) {
         $this->counters['fail_bing']++;
         return "";
      }

       $this->debug( __METHOD__, "simple", 5, $server_output);
      // "text/javascript; charset=utf-8"
      $contents = $server_output; 
      if (preg_match("/utf-8/", strtolower($curlinfo['content_type']), $matches)) {
         if (!empty($matches[0])) {
            $this->debug( __METHOD__, "simple", 3, "Encoding utf-8");
            $contents = utf8_encode($server_output); 
         }
      }

      $this->bing_page = array ('curlinfo' => $curlinfo, 'contents' => $contents);
      // Store in the cache if we can
      if(isset($this->MC)){
         $this->debug(__METHOD__, "simple",2,"Saving in cache");
         $this->MC->set($mckey, $this->bing_page, $this->settings['mc_compress'], $this->settings['mc_expire']);
         $this->counters['memc_set']++;
      }
      $this->counters['bi_timer']=microtime(true);
      return 1;
   }


   private function revgeocode_yahoo () {
      $this->debug(__METHOD__, "call",5);
      $tag='yahoo';

      // The memcache key
      $mckey=sprintf("RG%s_%s|%s",strtoupper(substr($tag,0,4)), $this->float_to_small($this->lat), $this->float_to_small($this->lon));

      // Fetch from cache if we can 
      if(isset($this->MC)){
         $this->debug(__METHOD__, "simple",3,"Checking cache...");
         $this->debug(__METHOD__, "simple",3,sprintf("Memcache key %s",$mckey));
         $cached = $this->MC->get($mckey);
         $this->counters['memc_get']++;
         $page = json_decode($cached['contents'],true);
         if (is_array($page)) {
            $this->debug(__METHOD__, "simple",3,"Cached data found.");
            // Seems we have some valid cache data for this service
            $this->yahoo_page=$cached;
            $this->counters['memc_hit']++;
            return 1;
         } else {
            $this->counters['memc_miss']++;
         }
      }

      if($this->counters['fail_yahoo']>$this->settings['yahoo_max_fail'] OR !$this->settings['can_use_yahoo']) {
         $this->settings['use_yahoo']=0;
         return "";
      }

      if (!empty($this->settings['dryrun'])) {
         $this->debug(__METHOD__, "simple",5,"Dryrun active, not doing encode now.");
         return "";
      }

      if(empty($this->settings['use_yahoo'])) {
         $this->debug(__METHOD__, "simple",0,sprintf("Engine %s is disabled",$tag));
         return "";
      }
      $this->throttle_service($tag);
      $this->debug( __METHOD__, "simple", 2, sprintf("Encoding with Yahoo placefinder"));

      /* http://where.yahooapis.com/geocode?q=%1$s,+%2$s&gflags=R&appid=[yourappidhere] */
      $baseurl = "http://where.yahooapis.com/geocode?q=";
      $url = $baseurl . $this->lat .",".$this->lon."&gflags=R&flags=J&appid=" . $this->settings['key_yahoo'] . "&locale=nl";

      $this->debug( __METHOD__, "simple", 2, sprintf("Geocoding url '%s'", $url));
      $this->counters['hit_yahoo']++;

      /* Do it with curl */
      $ch = curl_init($url);

      // set user agent
      curl_setopt($ch, CURLOPT_USERAGENT, $this->settings['user_agent_string']);

      curl_setopt($ch, CURLOPT_RETURNTRANSFER , true);
      $server_output = curl_exec($ch);
      $curlinfo = curl_getinfo($ch);
      curl_close($ch);

      $this->debug( __METHOD__, "simple", 5, $curlinfo,1);


      if ($curlinfo['http_code']!=200) {
         $this->counters['fail_yahoo']++;
         return "";
      }

      // $this->debug( __METHOD__, "simple", 5, $server_output);

      // "text/javascript; charset=utf-8"
      $contents = $server_output; 
      if (preg_match("/utf-8/", strtolower($curlinfo['content_type']), $matches)) {
         if (!empty($matches[0])) {
            $this->debug( __METHOD__, "simple", 3, "Encoding utf-8");
            $contents = utf8_encode($server_output); 
         }
      }

      $this->yahoo_page = array ('curlinfo' => $curlinfo, 'contents' => $contents);
      if(isset($this->MC)){
         $this->debug(__METHOD__, "simple",2,"Saving in cache");
         $this->MC->set($mckey, $this->yahoo_page, $this->settings['mc_compress'], $this->settings['mc_expire']);
         $this->counters['memc_set']++;
      }
      $this->counters['ya_timer']=microtime(true);
      return 1;
   }


   private function revgeocode_geonames () {
      $this->debug(__METHOD__, "call",5);
      $tag='geonames';

      // The memcache key
      $mckey=sprintf("RG%s_%s|%s",strtoupper(substr($tag,0,4)), $this->float_to_small($this->lat), $this->float_to_small($this->lon));

      // Fetch from cache if we can 
      if(isset($this->MC)){
         $this->debug(__METHOD__, "simple",3,"Checking cache...");
         $this->debug(__METHOD__, "simple",3,sprintf("Memcache key %s",$mckey));
         $cached = $this->MC->get($mckey);
         $this->counters['memc_get']++;
         $page = json_decode($cached['contents'],true);
         if (is_array($page)) {
            $this->debug(__METHOD__, "simple",3,"Cached data found.");
            // Seems we have some valid cache data for this service
            $this->geonames_page=$cached;
            $this->counters['memc_hit']++;
            return 1;
         } else {
            $this->counters['memc_miss']++;
         }
      }

      if($this->counters['fail_geonames']>$this->settings['geonames_max_fail'] OR !$this->settings['can_use_geonames']) {
         $this->settings['use_geonames']=0;
         return "";
      }

      if (!empty($this->settings['dryrun'])) {
         $this->debug(__METHOD__, "simple",5,"Dryrun active, not doing encode now.");
         return "";
      }


      if(empty($this->settings['use_geonames'])) {
         $this->debug(__METHOD__, "simple",0,sprintf("Engine %s is disabled",$tag));
         return "";
      }

      $this->throttle_service($tag);
      $this->debug( __METHOD__, "simple", 2, sprintf("Encoding with GeoNames JSON API"));

      #$baseurl = "http://api.geonames.org/findNearbyPlaceNameJSON?lat=%s&lng=%s&username=%s&style=full";
      $baseurl = "http://api.geonames.org/findNearbyStreetsOSMJSON?lat=%s&lng=%s&username=%s&style=full";
      # http://api.geonames.org/findNearbyPlaceNameJSON?lat=50.974383&lng=4.467943&username=demo
      # 51.158705&lon=4.99776166667
      # view-source:http://api.geonames.org/findNearbyStreetsOSMJSON?lat=51.158705&lng=4.99776166667&username=demo%20%2051.158705&lon=4.99776166667
      # http://api.geonames.org/findNearbyStreetsOSMJSON?lat=51.158705&lng=4.99776166667&username=demo%20%2051.158705&lon=4.99776166667

      $url = sprintf($baseurl,$this->lat,$this->lon,$this->settings['key_geonames']);
      $this->debug( __METHOD__, "simple", 2, sprintf("Geocoding url '%s'", $url));
      $this->counters['hit_geonames']++;

      /* Do it with curl */
      $ch = curl_init($url);

      // set user agent
      curl_setopt($ch, CURLOPT_USERAGENT, $this->settings['user_agent_string']);

      curl_setopt($ch, CURLOPT_RETURNTRANSFER , true);
      $server_output = curl_exec($ch);
      $curlinfo = curl_getinfo($ch);
      curl_close($ch);

      $this->debug( __METHOD__, "simple", 5, $curlinfo,1);

      if ($curlinfo['http_code']!=200) {
         $this->counters['fail_geonames']++;
         return "";
      }

      // $this->debug( __METHOD__, "simple", 5, $server_output);

      // "text/javascript; charset=utf-8"
      $contents = $server_output; 
      if (preg_match("/utf-8/", strtolower($curlinfo['content_type']), $matches)) {
         if (!empty($matches[0])) {
            $this->debug( __METHOD__, "simple", 3, "Encoding utf-8");
            $contents = utf8_encode($server_output); 
         }
      }

      $this->geonames_page = array ('curlinfo' => $curlinfo, 'contents' => $contents);
      if(isset($this->MC)){
         $this->debug(__METHOD__, "simple",2,"Saving in cache");
         $this->MC->set($mckey, $this->geonames_page, $this->settings['mc_compress'], $this->settings['mc_expire']);
         $this->counters['memc_set']++;
      }
      $this->counters['ge_timer']=microtime(true);
      return 1;
   }

   private function revgeocode_cloudmade () {
      $this->debug(__METHOD__, "call",5);
      $tag='cloudmade';

      // The memcache key
      $mckey=sprintf("RG%s_%s|%s",strtoupper(substr($tag,0,4)), $this->float_to_small($this->lat), $this->float_to_small($this->lon));

      // Fetch from cache if we can 
      if(isset($this->MC)){
         $this->debug(__METHOD__, "simple",3,"Checking cache...");
         $this->debug(__METHOD__, "simple",3,sprintf("Memcache key %s",$mckey));
         $cached = $this->MC->get($mckey);
         $this->counters['memc_get']++;
         $page = json_decode($cached['contents'],true);
         if (is_array($page)) {
            $this->debug(__METHOD__, "simple",3,"Cached data found.");
            // Seems we have some valid cache data for this service
            $this->cloudmade_page=$cached;
            $this->counters['memc_hit']++;
            return 1;
         } else {
            $this->counters['memc_miss']++;
         }
      }

      if($this->counters['fail_cloudmade']>$this->settings['cloudmade_max_fail'] OR !$this->settings['can_use_cloudmade']) {
         $this->settings['use_cloudmade']=0;
         return "";
      }

      if (!empty($this->settings['dryrun'])) {
         $this->debug(__METHOD__, "simple",1,"Dryrun active, not doing encode now.");
         return "";
      }

      if(empty($this->settings['use_cloudmade'])) {
         $this->debug(__METHOD__, "simple",0,sprintf("Engine %s is disabled",$tag));
         return "";

      }
      $this->throttle_service($tag);
      $this->debug( __METHOD__, "simple", 2, sprintf("Encoding with Cloudmade"));

      // http://geocoding.cloudmade.com/<an_api_key>/geocoding/v2/find.js?object_type=address&around=51.0433583233,4.49876833333&distance=closest
		$baseurl = "http://geocoding.cloudmade.com/%s/geocoding/v2/find.js?object_type=address&around=%s,%s&distance=closest";
      $url = sprintf($baseurl,$this->settings['key_cloudmade'], $this->lat,$this->lon);

      $this->debug( __METHOD__, "simple", 2, sprintf("Geocoding url '%s'", $url));
      $this->counters['hit_cloudmade']++;

      /* Do it with curl */
      $ch = curl_init($url);

      // set user agent
      curl_setopt($ch, CURLOPT_USERAGENT, $this->settings['user_agent_string']);

      curl_setopt($ch, CURLOPT_RETURNTRANSFER , true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('HTTP_ACCEPT_LANGUAGE: UTF-8')); # We need this for the code I found in gazetteer to not throw errors

      $server_output = curl_exec($ch);
      $curlinfo = curl_getinfo($ch);
      curl_close($ch);

      $this->debug( __METHOD__, "simple", 5, $curlinfo,1);

      if ($curlinfo['http_code']!=200) {
         $this->counters['fail_cloudmade']++;
         return "";
      }

      // $this->debug( __METHOD__, "simple", 5, $server_output);
      $contents = $server_output; 
      if (preg_match("/utf-8/", strtolower($curlinfo['content_type']), $matches)) {
         if (!empty($matches[0])) {
            $this->debug( __METHOD__, "simple", 3, "Encoding utf-8");
            $contents = utf8_encode($server_output); 
         }
      }

      $this->cloudmade_page = array ('curlinfo' => $curlinfo, 'contents' => $contents);
      if(isset($this->MC)){
         $this->debug(__METHOD__, "simple",2,"Saving in cache");
         $this->MC->set($mckey, $this->cloudmade_page, $this->settings['mc_compress'], $this->settings['mc_expire']);
         $this->counters['memc_set']++;
      }
      $this->counters['cm_timer']=microtime(true);
      return 1;
   }

   private function revgeocode_nominatim () {
      // http://open.mapquestapi.com/nominatim/#reverse
      $this->debug(__METHOD__, "call",5);
      $tag='nominatim';

      // The memcache key
      $mckey=sprintf("RG%s_%s|%s",strtoupper(substr($tag,0,4)), $this->float_to_small($this->lat), $this->float_to_small($this->lon));

      // Fetch from cache if we can 
      if(isset($this->MC)){
         $this->debug(__METHOD__, "simple",3,"Checking cache...");
         $this->debug(__METHOD__, "simple",3,sprintf("Memcache key %s",$mckey));
         $cached = $this->MC->get($mckey);
         $this->counters['memc_get']++;
         $page = json_decode($cached['contents'],true);
         if (is_array($page)) {
            $this->debug(__METHOD__, "simple",3,"Cached data found.");
            // Seems we have some valid cache data for this service
            $this->nominatim_page=$cached;
            $this->counters['memc_hit']++;
            return 1;
         } else {
            $this->counters['memc_miss']++;
         }
      }

      /* WARNING 
      This runs on my own box for my own use and I want this to keep hitting gazetteer for my own reasons, so check is disabled....

      if($this->counters['fail_nominatim']>$this->settings['nominatim_max_fail'] OR !$this->settings['can_use_nominatim']) {
         $this->settings['use_nominatim']=0;
         return "";
      }
       */

      if (!empty($this->settings['dryrun'])) {
         $this->debug(__METHOD__, "simple",1,"Dryrun active, not doing encode now.");
         return "";
      }

      if(empty($this->settings['use_nominatim'])) {
         return "";

      }
      $this->throttle_service($tag);
      $this->debug( __METHOD__, "simple", 2, sprintf("Encoding with Nominatim"));

      // The standard one
      //$baseurl = "http://open.mapquestapi.com/nominatim/v1/reverse?format=json&lat=%s&lon=%s&email=%s";
      // My own, with only belgium covered, this should go into the options really
		$baseurl = "http://gazzy.dyndns.org:8888/reverse.php?format=json&lat=%s&lon=%s&zoom=18&addressdetails=1&email=%s&accept-language=nl,en;q=0.8,fr;q=0.5";
      $url = sprintf($baseurl,$this->lat,$this->lon,$this->settings['key_nominatim']);

      $this->debug( __METHOD__, "simple", 2, sprintf("Geocoding url '%s'", $url));
      $this->counters['hit_nominatim']++;

      /* Do it with curl */
      $ch = curl_init($url);

      // set user agent
      curl_setopt($ch, CURLOPT_USERAGENT, $this->settings['user_agent_string']);

      curl_setopt($ch, CURLOPT_RETURNTRANSFER , true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('HTTP_ACCEPT_LANGUAGE: UTF-8')); # We need this for the code I found in gazetteer to not throw errors

      $server_output = curl_exec($ch);
      $curlinfo = curl_getinfo($ch);
      curl_close($ch);

      $this->debug( __METHOD__, "simple", 5, $curlinfo,1);

      if ($curlinfo['http_code']!=200) {
         $this->counters['fail_nominatim']++;
         return "";
      }

      // $this->debug( __METHOD__, "simple", 5, $server_output);

      // "text/javascript; charset=utf-8"
      $contents = $server_output; 
      if (preg_match("/utf-8/", strtolower($curlinfo['content_type']), $matches)) {
         if (!empty($matches[0])) {
            $this->debug( __METHOD__, "simple", 3, "Encoding utf-8");
            $contents = utf8_encode($server_output); 
         }
      }

      $this->nominatim_page = array ('curlinfo' => $curlinfo, 'contents' => $contents);
      if(isset($this->MC)){
         $this->debug(__METHOD__, "simple",2,"Saving in cache");
         $this->MC->set($mckey, $this->nominatim_page, $this->settings['mc_compress'], $this->settings['mc_expire']);
         $this->counters['memc_set']++;
      }
      $this->counters['no_timer']=microtime(true);
      return 1;
   }


   // Helper functions
   private function post_filter_address($location) {
      if (empty($location)) {
         return "";
      }
      $this->debug(__METHOD__, "call",5);
      $location = preg_replace("/ Belgium/"," BE", $location);
      $location = preg_replace("/ The Netherlands/"," NL", $location);
      $location = preg_replace("/ Netherlands/"," NL", $location);
      $location = preg_replace("/ France/"," FR", $location);
      $location = preg_replace("/ Ghent/"," Gent", $location);
      $location = preg_replace("/ Antwerp$/"," Antwerpen", $location);
      $location = preg_replace("/ Arrondissement/","", $location);
      $location = preg_replace("/^, BE$/","", $location);
      $location = preg_replace("/^, LU$/","", $location);
      $location = preg_replace("/^, FR$/","", $location);
      $location = preg_replace("/^, NL$/","", $location);
      $location = preg_replace("/^, DE$/","", $location);
      $location = preg_replace("/^BE$/","", $location);
      $location = preg_replace("/^NL$/","", $location);
      $location = preg_replace("/^FR$/","", $location);
      $location = preg_replace("/^UK$/","", $location);
      $location = preg_replace("/^DE$/","", $location);
      $location = preg_replace("/^LU$/","", $location);
      $location = preg_replace("/Province/","", $location);
      $location = preg_replace("/^,$/","", $location);
      $this->debug(__METHOD__, "hangup",5);
      return(trim($location));
   }

/*
 *
 * This function only works in 5.3 PHP
 *
   public static function json_printable_encode($in, $indent = 3, $from_array = false) {
      $_escape = function ($str)
      {
         return preg_replace("!([\b\t\n\r\f\"\\'])!", "\\\\\\1", $str);
      };

      $out = '';

      foreach ($in as $key=>$value) {
         $out .= str_repeat("\t", $indent + 1);
         $out .= "\"".$_escape((string)$key)."\": ";

         if (is_object($value) || is_array($value)) {
            $out .= "\n";
            $out .= $this->json_printable_encode($value, $indent + 1);
         } elseif (is_bool($value)) {
            $out .= $value ? 'true' : 'false';
         } elseif (is_null($value)) {
            $out .= 'null';
         } elseif (is_string($value)) {
            $out .= "\"" . $_escape($value) ."\"";
         } else {
            $out .= $value;
         }
         $out .= ",\n";
      }

      if (!empty($out)) {
         $out = substr($out, 0, -2);
      }

      $out = str_repeat("\t", $indent) . "{\n" . $out;
      $out .= "\n" . str_repeat("\t", $indent) . "}";

      return $out;
   }
*/

   // Google V3 functions
   public static function encode_base64_url_safe($value) {
      //$this->debug(__METHOD__, "call",5);
      return str_replace(array('+', '/'), array('-', '_'), base64_encode($value));
   }

   public static function decode_base64_url_safe($value) {
      //$this->debug(__METHOD__, "call",5);
      return base64_decode(str_replace(array('-', '_'), array('+', '/'), $value));
   }

   private function sign_url($url_to_sign) {
      /* This functions signs google v3 urls with GME keys */
      $this->debug(__METHOD__, "call",5);
      //parse the url
      $this->debug(__METHOD__, "simple" , 1, sprintf("Using crypto key '%s' for signing.", $this->settings['google_cryptokey'] ));
      $url = parse_url($url_to_sign);

      $urlToSign =  $url['path'] . "?" . $url['query'];

      // Decode the private key into its binary format
      $decodedKey = $this->decode_base64_url_safe($this->settings['google_cryptokey']);

      // Create a signature using the private key and the URL-encoded
      // string using HMAC SHA1. This signature will be binary.
      $signature = hash_hmac("sha1", $urlToSign, $decodedKey,true);

      //make encode Signature and make it URL Safe
      $encodedSignature = $this->encode_base64_url_safe($signature);

      // $originalUrl = $url['scheme'] . "://" . $url['host'] . $url['path'] . "?" . $url['query'];
      $this->debug(__METHOD__, "simple" , 1, sprintf("Signature for this url is '%s'", $encodedSignature));
      $this->debug(__METHOD__, "hangup",5);
      return $encodedSignature;
   }

   // I just miss working with perl
   public static function my_chomp(&$string) {
      //$this->debug(__METHOD__, "call",5);
      if (is_array($string)) {
         foreach($string as $i => $val) {
            $endchar = chomp($string[$i]);
         }
      } else {
         $endchar = substr("$string", strlen("$string") - 1, 1);
         $string = substr("$string", 0, -1);
      }
      return $endchar;
   }

   // Use this to create ints from lat/lon floats , so you can use they as memcache keys
   public static function small_to_float($LatitudeSmall) {
      if(($LatitudeSmall>0)&&($LatitudeSmall>>31)) {
         $LatitudeSmall=-(0x7FFFFFFF-($LatitudeSmall&0x7FFFFFFF))-1;
      }
      return (float)$LatitudeSmall/(float)600000;
   }

   public static function float_to_small($LongitudeFloat) {
      $Longitude=round((float)$LongitudeFloat*(float)600000);
      if($Longitude<0) { 
         $Longitude+=0xFFFFFFFF; 
      }
      return $Longitude;
   }

   private function count_engines_available($specific_engine=null){
      $this->debug(__METHOD__, "call",5);
      $count=count($this->get_engines_available($specific_engine));
      $this->debug( __METHOD__, "simple", 3, sprintf("Engine count -> %s",$count));
      return $count;
   }

   // timer core 
   private function throttle_service($service_name) {
      $this->debug(__METHOD__, "call",5);

      if (empty($service_name)) {
         $this->debug( __METHOD__, "simple", 0, sprintf("Empty service_name"));
         sleep(2);
         return -1;
      }

      // Can be :
      // bing
      // yahoo
      // nominatim
      // google
      // geonames

      // See __contstruct, this could probably be done better than using service_variable_map idea
      $target_timer = $this->service_variable_map[$service_name]['timer_name'];

      if (empty($target_timer)) {
         $this->debug( __METHOD__, "simple", 0, sprintf("Unknown service %s",$service_name));
         sleep(2);
         return -1;
      }

# in microseconds
      $sleep_service = $this->service_variable_map[$service_name]['sleep_setting'];
      $minimum_sleep = $this->settings[$sleep_service]*1000;

      $this->debug( __METHOD__, "simple", 4, sprintf("%s",microtime(true)));
      $this->debug( __METHOD__, "simple", 4, sprintf("Minsleep for %s is %dus",$service_name, $minimum_sleep));
# microtime is a float in seconds and we are working in microseconds so adjust
      $interval_since_last = (microtime(true) - $this->counters[$target_timer] ) * 1000000; 
      $this->debug( __METHOD__, "simple", 3, sprintf("Interval since last is %dus",$interval_since_last));

# can we issue the request yet or do we need some more sleep
      if ($interval_since_last < $minimum_sleep) {
         $wait = $minimum_sleep - $interval_since_last;
         //$this->debug( __METHOD__, "simple", 0, array ('now'=> microtime(true), 'bi_timer'=> $this->counters['bi_timer'], 'interval'=> $interval_since_last, 'min'=> $minimum_sleep, 'wait'=> $wait), 2);
         $this->debug( __METHOD__, "simple", 3, sprintf("Delaying call for %dus",$wait));
         usleep($wait);
         // $spare = $interval_since_last - $minimum_sleep;
         // $this->debug( __METHOD__, "simple", 0, sprintf("Not waiting , spare %s time is %s us",$service_name, $spare));
      } else {
         $this->debug( __METHOD__, "simple", 3, sprintf("Been longer than %ss",$this->settings[$sleep_service]));
      }
      $this->debug(__METHOD__, "hangup",5);
      return 1;
   }

   public function get_street_name_google($lat=null,$lon=null) {
      $this->debug(__METHOD__, "call",5);

      if(empty($this->settings['use_google'])) {
         return "";
      }

      if(isset($lat) and isset($lon)) {
         if(!$this->set_coord($lat,$lon)) {
            $this->debug(__METHOD__, "simple", 0, sprintf("Bad coordinates lat = %s, lon = %s",$lat,$lon));
            return "";
         }
      } elseif(!isset($this->lat) or !isset($this->lon)) {
         $this->debug(__METHOD__, "simple", 0, sprintf("Need to set the coordinates first or pass them as lat/lon to function %s",__FUNCTION__));
         return "";
      }

      $this->debug(__METHOD__, "simple", 1, sprintf("Checking coordinates lat = %s, lon = %s",$this->lat, $this->lon));

      $this->revgeocode_google();

      $page = json_decode($this->google_page['contents'],true);

      if (!is_array($page)) {
         $this->debug( __METHOD__, "simple", 0,"Problem reading geocode results. Have you geocoded yet?");
         $this->debug( __METHOD__, "simple", 0,sprintf("%s",$page));
         return ""; 
      }

      $this->debug( __METHOD__, "simple", 2,print_r($page,true),1);

      // We can easily perform more in depth checks with google
      $status = $page['Status']['code'];
      if (strcmp($status, "200") == 0) {
         $this->counters['ok_google']++;
         $this->debug(__METHOD__, "simple" , 3, "Ok, We have 200 status code");
      }

      $this->debug( __METHOD__, "simple", 3, sprintf("RevGeo = %s|%s result = %s",$this->lat, $this->lon, $this->google_page['curlinfo']['http_code']));
      $newaddress="";

      // Check the return status from google
      $this->debug(__METHOD__, "simple" , 3, sprintf("Received google status code '%d'.", $status));
      /* Status Codes:
       *
       * The "status" field within the Geocoding response object contains the status of the request, 
       * and may contain debugging information to help you track down why Geocoding is not working. 
       * The "status" field may contain the following values:
       *
       * * "OK" indicates that no errors occurred; the address was successfully parsed and at least one geocode was returned.
       * * "ZERO_RESULTS" indicates that the geocode was successful but returned no results. This may occur if the geocode was passed a 
       *                  non-existent address or a latlng in a remote location.
       * * "OVER_QUERY_LIMIT" indicates that you are over your quota.
       * * "REQUEST_DENIED" indicates that your request was denied, generally because of lack of a sensor parameter.
       * * "INVALID_REQUEST" generally indicates that the query (address or latlng) is missing.
       * G_GEO_SUCCESS = 200 
       *  - No errors occurred; the address was successfully parsed and its geocode has been returned.  (Since 2.55)
       * G_GEO_BAD_REQUEST = 400 
       *  - A directions request could not be successfully parsed. For example, the request may have been rejected 
       if it contained more than the maximum number of waypoints allowed.
       * G_GEO_SERVER_ERROR = 500 
       *  - A geocoding, directions or maximum zoom level request could not be successfully processed, 
       yet the exact reason for the failure is not known.
       * G_GEO_MISSING_QUERY = 601 
       *  - The HTTP q parameter was either missing or had no value. For geocoding requests, this means 
       that an empty address was specified as input. For directions requests, this means that no query was specified in the input.
       * G_GEO_MISSING_ADDRESS = 601
       *  -  Synonym for G_GEO_MISSING_QUERY.
       * G_GEO_UNKNOWN_ADDRESS = 602 
       *  - No corresponding geographic location could be found for the specified address. 
       This may be due to the fact that the address is relatively new, or it may be incorrect.
       * G_GEO_UNAVAILABLE_ADDRESS = 603 
       *  - The geocode for the given address or the route for the given directions query cannot be 
       returned due to legal or contractual reasons.
       * G_GEO_UNKNOWN_DIRECTIONS = 604 
       *  - The GDirections object could not compute directions between the points mentioned in the query. 
       This is usually because there is no route available between the two points, or because we do not 
       have data for routing in that region.
       * G_GEO_BAD_KEY = 610 
       *  - The given key is either invalid or does not match the domain for which it was given.
       * G_GEO_TOO_MANY_QUERIES = 620 
       *  - The given key has gone over the requests limit in the 24 hour period or has submitted too many requests in too 
       short a period of time. If you're sending multiple requests in parallel or in a tight loop, use a timer or pause 
       in your code to make sure you don't send the requests too quickly. 
       */
      if (strcmp($status, "200") == 0) {
         // Successful geocode, google we use 1 col to get what we want
         $newaddress=trim(utf8_decode($page['Placemark'][0]['address']));
         $newaddress=$this->trans->mixed_to_utf8($this->post_filter_address($newaddress));

         //         if (preg_match('/\d+ \/ \w+/', $newaddress, $matches)) {
         //            if (empty($matches[0])) {
         //               exit;
         //            }
         //         }

         $this->debug(__METHOD__, "simple" , 3, sprintf("Address is : '%s'", $newaddress));
      } elseif (strcmp($status, "601") == 0) {
         // We are hitting a query problem
         $this->debug(__METHOD__, "simple" , 1, sprintf("Warning, Google status : '%s'. Skipping record.", $status));
         return "";
      } elseif (strcmp($status, "602") == 0) {
         // We are hitting some max need to investigate 
         $this->debug(__METHOD__, "simple" , 1, sprintf("Warnings, Google status : '%s'. Skip, are we offroad ?", $status));
         // Increment google encoding sleep delay some
         $this->settings['sleep_google']=$this->settings['sleep_google']+2000;
         return "";
      } elseif (strcmp($status, "620") == 0) {
         // We are hitting some max need to investigate
         $this->debug(__METHOD__, "simple" , 1, sprintf("Error, Google says : '%s'. Bail out!", $status));
         // $this->debug(__METHOD__, "simple" , 2, sprintf("Dumping result: ", print_r($page,true)));
         return "";
      } else {
         $this->debug(__METHOD__, "simple" , 1, sprintf("Other Error, Google status : '%s'. Bail out!", $status));
         return "";
      }
      $this->debug(__METHOD__, "hangup",5);
      return $newaddress;
   }

   public function get_street_name_bing($lat=null,$lon=null) {
      $this->debug(__METHOD__, "call",5);

      if(empty($this->settings['use_bing'])) {
         return "";
      }

      if(isset($lat) and isset($lon)) {
         if(!$this->set_coord($lat,$lon)) {
            $this->debug(__METHOD__, "simple", 0, sprintf("Bad coordinates lat = %s, lon = %s",$lat,$lon));
            return "";
         }
      } elseif(!isset($this->lat) or !isset($this->lon)) {
         $this->debug(__METHOD__, "simple", 0, sprintf("Need to set the coordinates first or pass them as lat/lon to function %s",__FUNCTION__));
         return "";
      }

      $this->debug(__METHOD__, "simple", 1, sprintf("Checking coordinates lat = %s, lon = %s",$this->lat, $this->lon));

      $this->revgeocode_bing();

      $page = json_decode($this->bing_page['contents'],true);

      if (!is_array($page)) {
         $this->debug( __METHOD__, "simple", 0,"Problem reading geocode results. Have you geocoded yet?");
         return ""; 
      }

      $this->debug( __METHOD__, "simple", 2,print_r($page,true),1);

      $count = count($page['resourceSets']);

      if($count>0) {
         $address = $page['resourceSets'][0]['resources'][0]['address']['formattedAddress'];
      }

      //logtrace(2, print_r($page,true));
      //logtrace(2, print_r($page,true));
      $this->debug( __METHOD__, "simple", 3, sprintf("RevGeo = %s|%s result = %s",$this->lat, $this->lon, $this->bing_page['curlinfo']['http_code']));

      $newaddress="";
      if ($this->bing_page['curlinfo']['http_code']==200) {
         $this->counters['ok_bing']++;
         $newaddress=$this->trans->mixed_to_utf8($this->post_filter_address($address));

         // "51.0801183333,4.41619666667, Rumst, BE"
         $filter_out=sprintf("/%s,%s, /",$this->lat,$this->lon);
         $newaddress = preg_replace($filter_out,"", $newaddress);

         if (strlen($newaddress)<1) {
            $newaddress=NULL;
         }
         //  '7100, BE'.
         if (preg_match('/^([0-9]{4}), BE$/',$newaddress,$match)) {
            $pc = $match[1];
            $place=$this->getpostcodeinfo($pc);
            //$this->debug( __METHOD__, "simple", 1, print_r($place,true));
            $contents = utf8_encode($place); 
            $pp= json_decode($place,true);
            //print_r($pp);
/*
            Array
               (
                  [status] => Array
                  (
                     [code] => 200
                     [message] => Found multiple records
                     [found] => 4
                  )

                  [place] => Array
                  (
                     [0] => Array
                     (
                        [postcode] => 9130
                        [city] => Doel
                     )

                     [1] => Array
                     (
                        [postcode] => 9130
                        [city] => Verrebroek
                     )

                     [2] => Array
                     (
                        [postcode] => 9130
                        [city] => Kallo (Kieldrecht)
                     )

                     [3] => Array
                     (
                        [postcode] => 9130
                        [city] => Kieldrecht (Beveren)
                     )

                  )

               )
 */

            $newaddress = sprintf("%d %s, BE",$pc,$pp['place'][0]['city']);
         } elseif (preg_match('/^(.+), ([0-9]{4}), BE$/',$newaddress,$match)) {
            // 'Kiefhoekstraat, 3941, BE'.
            $pc = $match[2];
            $street = $match[1];
            $place=$this->getpostcodeinfo($pc);
            //$this->debug( __METHOD__, "simple", 1, print_r($place,true));
            // print_r($place);
            // print $place;
            //$newaddress = sprintf("%d %s, BE",$pc,$place['place'][0]=>'city']);
            //$this->debug( __METHOD__, "simple", 1, print_r($place,true));
            $contents = utf8_encode($place); 
            $pp= json_decode($place,true);
            $newaddress = sprintf("%d %s, BE",$street,$pc,$pp['place'][0]['city']);
         }

         $this->debug( __METHOD__, "simple", 2, sprintf("Bing encoded is: '%s'.", $newaddress));
      } elseif ($status>0){
         /* We are hitting a query problem, just skip this record */
         $this->debug( __METHOD__, "simple", 3, sprintf("Warning, Bing says : '%s'.", $message));
         $this->counters['fail_bing']++;
         $this->settings['sleep_bing']=$this->settings['sleep_bing'] + 500;
      }
      $this->debug(__METHOD__, "hangup",5);
      return $newaddress;
   }

   public function get_street_name_yahoo($lat=null,$lon=null) {
      $this->debug(__METHOD__, "call",5);

      if(empty($this->settings['use_yahoo'])) {
         return "";
      }

      if(isset($lat) and isset($lon)) {
         if(!$this->set_coord($lat,$lon)) {
            $this->debug(__METHOD__, "simple", 0, sprintf("Bad coordinates lat = %s, lon = %s",$lat,$lon));
            return "";
         }
      } elseif(!isset($this->lat) or !isset($this->lon)) {
         $this->debug(__METHOD__, "simple", 0, sprintf("Need to set the coordinates first or pass them as lat/lon to function %s",__FUNCTION__));
         return "";
      }

      $this->debug(__METHOD__, "simple", 1, sprintf("Checking coordinates lat = %s, lon = %s",$this->lat, $this->lon));

      $this->revgeocode_yahoo();

      $page = json_decode($this->yahoo_page['contents'],true);

      if (!is_array($page)) {
         $this->debug( __METHOD__, "simple", 0,"Problem reading geocode results. Have you geocoded yet?");
         return ""; 
      }

      $this->debug( __METHOD__, "simple", 2,print_r($page,true),1);

      $status = $page['ResultSet']['Error'];
      $message = $page['ResultSet']['ErrorMessage'];
      $found = $page['ResultSet']['Found'];
      $count = count($page['ResultSet']['Results']);
      $address="";

      if($count>0) {
         $address = $found = $page['ResultSet']['Results'][0]['line1'] .", " . $page['ResultSet']['Results'][0]['line2'] . ", " . $page['ResultSet']['Results'][0]['countrycode'];
      }
      $this->debug( __METHOD__, "simple", 3, sprintf("RevGeo = %s|%s result = %s",$this->lat, $this->lon, $this->yahoo_page['curlinfo']['http_code']));

      $newaddress="";

      if ($status==0) {
         $this->counters['ok_yahoo']++;
         // Successful geocode
         $newaddress=trim($this->trans->mixed_to_utf8($this->post_filter_address($address)));
         /* When yahoo doesn't know the street for sure it just returns the coordinates it received 
          * and places this in the 'name' field where we otherwise get our streetname from , isn't that great.  So we need to filter these out 
          * [name] => 42.510327,-89.937513
          * will end up as : "51.0801183333,4.41619666667, Rumst, BE" as streetname, but when those coordinates aren't there in other cases, 
          * their streetname is quite on the mark, very bizar behavior....  I'm missing the point of why here
          */
         $filter_out=sprintf("/%s,%s, /",$this->lat,$this->lon);
         $newaddress = preg_replace($filter_out,"", $newaddress);

         if (strlen($newaddress)<1) {
            $newaddress=NULL;
         }
         $this->debug( __METHOD__, "simple", 2, sprintf("Yahoo encoded is: '%s'.", $newaddress));
      } elseif ($status>0){
         /* We are hitting a query problem, just skip this record */
         $this->debug( __METHOD__, "simple", 3, sprintf("Warning, Yahoo says : '%s'.", $message));
         $this->counters['fail_yahoo']++;
         $this->settings['sleep_yahoo']=$this->settings['sleep_yahoo'] + 500;
      }
      $this->debug(__METHOD__, "hangup",5);
      return $newaddress;
   }

   public function get_street_name_geonames($lat=null,$lon=null) {
      $this->debug(__METHOD__, "call",5);

      if(empty($this->settings['use_geonames'])) {
         return "";
      }

      if(isset($lat) and isset($lon)) {
         if(!$this->set_coord($lat,$lon)) {
            $this->debug(__METHOD__, "simple", 0, sprintf("Bad coordinates lat = %s, lon = %s",$lat,$lon));
            return "";
         }
      } elseif(!isset($this->lat) or !isset($this->lon)) {
         $this->debug(__METHOD__, "simple", 0, sprintf("Need to set the coordinates first or pass them as lat/lon to function %s",__FUNCTION__));
         return "";
      }

      $this->debug(__METHOD__, "simple", 1, sprintf("Checking coordinates lat = %s, lon = %s",$this->lat, $this->lon));

      $this->revgeocode_geonames();

      //var_dump($this->geonames_page['contents']); exit;

      $page = json_decode($this->geonames_page['contents'],true);

      if (!is_array($page)) {
         $this->debug( __METHOD__, "simple", 0,"Problem reading geocode results. Have you geocoded yet?");
         return ""; 
      }

      $this->debug( __METHOD__, "simple", 2,print_r($page,true),1);

      $address="";


         //var_dump($page['streetSegment']); exit(1);
      if (empty($page['streetSegment'])) {
         $this->debug( __METHOD__, "simple", 0,"Nothing found for coordinates.");
         return ""; 
         //var_dump($page['streetSegment']); exit(1);
      }

      $count = count($page['streetSegment']);
      /* Geonames doesn't really have a great way to validate the content so lets try it by counting and checking for a field */
      $this->debug( __METHOD__, "simple", 2,sprintf("Count = %d", $count));

      if ($count > 0) {
         /* Trying to extract meaningfull data is a lot easier now from geonames with their OSM coverage!  Woohoo */
         $r_address = array();

/*
2012-04-12 16:59:44:[2]- [GeoRev::()]                     [ref] => N267
2012-04-12 16:59:44:[2]- [GeoRev::()]                     [distance] => 0.07
2012-04-12 16:59:44:[2]- [GeoRev::()]                     [highway] => secondary
2012-04-12 16:59:44:[2]- [GeoRev::()]                     [name] => Damstraat
2012-04-12 16:59:44:[2]- [GeoRev::()]                     [oneway] => true
2012-04-12 16:59:44:[2]- [GeoRev::()]                     [line] => 4.4663719 50.9747971,4.4670574 50.9747205
2012-04-12 16:59:44:[2]- [GeoRev::()]                     [maxspeed] => 50
*/
         $closest =(float)100;
         foreach ($page['streetSegment'] as $street ) {
            if (isset($street['distance'])) {
               if ($street['distance'] < $closest) {
                  if (!empty($street['name']) OR !empty($street['ref'])) {
                     if (empty($street['ref'])) {
                        $name = $street['name'];
                     } else {
                        $name = sprintf("%s (%s)", $street['name'], $street['ref']);
                     }
                     $r_address = array ( $name );
                  }
               }
            }
         }

         $address=implode(', ',$r_address);
         // $address = sprintf("%s %s, %s",$page['geonames'][0]['toponymName'], $page['geonames'][0]['countryCode']);
         // $message = $page['status']['message'];
      } else {
         $this->debug( __METHOD__, "simple", 0,"Error parsing geonames data");
         $this->debug( __METHOD__, "simple", 0,print_r($page,true));
         return "";
      }

      $this->debug( __METHOD__, "simple", 3, sprintf("RevGeo = %s|%s result = %s",$this->lat, $this->lon, $this->geonames_page['curlinfo']['http_code']));

      $newaddress="";

      if ($this->geonames_page['curlinfo']['http_code']==200) {
         $this->counters['ok_geonames']++;
         if (strlen($address)>0) {
            $newaddress=$this->trans->mixed_to_utf8($this->post_filter_address($address));
            $this->debug( __METHOD__, "simple", 2, sprintf("Geonames encoded is: '%s'.", $newaddress));
         } else {
            $this->debug( __METHOD__, "simple", 2, sprintf("Empty address line, check code."));
         }
      } else {
         $this->debug( __METHOD__, "simple", 3, sprintf("Warning, Geonames says : '%s'.", $this->geonames_page['curlinfo']['http_code']));
         $this->counters['fail_geonames']++;
         $this->settings['sleep_geonames']=$this->settings['sleep_geonames'] + 500;
      }
      $this->debug(__METHOD__, "hangup",5);
      return $newaddress;
   }

   public function get_street_name_nominatim($lat=null,$lon=null) {
      $this->debug(__METHOD__, "call",5);

      if(empty($this->settings['use_nominatim'])) {
         return "";
      }

      if(isset($lat) and isset($lon)) {
         if(!$this->set_coord($lat,$lon)) {
            $this->debug(__METHOD__, "simple", 0, sprintf("Bad coordinates lat = %s, lon = %s",$lat,$lon));
            return "";
         }
      } elseif(!isset($this->lat) or !isset($this->lon)) {
         $this->debug(__METHOD__, "simple", 0, sprintf("Need to set the coordinates first or pass them as lat/lon to function %s",__FUNCTION__));
         return "";
      }

      $this->debug(__METHOD__, "simple", 1, sprintf("Checking coordinates lat = %s, lon = %s",$this->lat, $this->lon));

      $this->revgeocode_nominatim();

      $page = json_decode($this->nominatim_page['contents'],true);

      if (!is_array($page)) {
         $this->debug( __METHOD__, "simple", 0,"Problem reading geocode results. Have you geocoded yet?");
         $this->debug( __METHOD__, "simple", 0,sprintf("%s",$page));
         return ""; 
      }

      $this->debug( __METHOD__, "simple", 2,print_r($page,true),1);

      /* Nominatim is much more straightforward to decode */
      if (isset($page['address'])) {
         $r_address = array();
         // Get the street if its there
         //
         // 2011-07-28 15:09:44:[3]- [GeoRev::()]             [road] => Rue De Rudder - De Rudderstraat
         // 2011-07-28 15:09:44:[3]- [GeoRev::()]             [town] => Sint-Jans-Molenbeek
         // 2011-07-28 15:09:44:[3]- [GeoRev::()]             [city] => Sint-Jans-Molenbeek
         // 2011-07-28 15:09:44:[3]- [GeoRev::()]             [state district] => Franse Gemeenschap
         // 2011-07-28 15:09:44:[3]- [GeoRev::()]             [state] => Brussels Hoofdstedelijk Gewest
         // 2011-07-28 15:09:44:[3]- [GeoRev::()]             [country] => BelgiÃ«
         // 2011-07-28 15:09:44:[3]- [GeoRev::()]             [country_code] => be
         // 2011-07-28 15:09:44:[3]- [GeoRev::()]             [postcode] => 1080
         //
         //
         // 2011-07-28 16:09:42:[2]- [GeoRev::()]             [road] => Grote Mierenstraat
         // 2011-07-28 16:09:42:[2]- [GeoRev::()]             [village] => Heffen
         // 2011-07-28 16:09:42:[2]- [GeoRev::()]             [city] => Mechelen
         // 2011-07-28 16:09:42:[2]- [GeoRev::()]             [boundary] => Brabantse Beek
         // 2011-07-28 16:09:42:[2]- [GeoRev::()]             [country] => BelgiÃ«
         // 2011-07-28 16:09:42:[2]- [GeoRev::()]             [country_code] => be
         // 2011-07-28 16:09:42:[2]- [GeoRev::()]             [postcode] => 2801
         //
         if (isset($page['address']['house_number']) and !empty($page['address']['house_number'])) {
            $house_number = $page['address']['house_number'];
         }
         // 011-07-28 23:54:37:[2]- [GeoRev::()] ( 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]     [place_id] => 305729 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]     [licence] => Data Copyright OpenStreetMap Contributors, Some Rights Reserved. CC-BY-SA 2.0. 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]     [osm_type] => way 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]     [osm_id] => 52403773 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]     [display_name] => De Dageraad, 7, Heiveldekens, Kontich, Waarloos, Mechelen, Brabantse Beek, BelgiÃ« 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]     [address] => Array 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]         ( 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]             [house_number] => 7 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]             [house] => De Dageraad 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]             [road] => Heiveldekens 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]             [city district] => Kontich 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]             [village] => Waarloos 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]             [city] => Kontich 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]             [boundary] => Brabantse Beek 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]             [country] => BelgiÃ« 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]             [country_code] => be 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]             [postcode] => 2550 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]         ) 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]  
         // 2011-07-28 23:54:37:[2]- [GeoRev::()] ) 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()] Array 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()] ( 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]     [0] => Heiveldekens7 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]     [1] => 2550 Kontich 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()]     [2] => BE 
         // 2011-07-28 23:54:37:[2]- [GeoRev::()] ) 
         //

         $house_number = '';
         if (strlen($house_number)>0) { 
            $house_number = sprintf(" %s",$house_number);
         }

         if (isset($page['address']['road']) and !empty($page['address']['road'])) {
            $r_address[] = ($page['address']['road']  . $house_number);
         } elseif (isset($page['address']['path']) and !empty($page['address']['path'])) {
            $r_address[] = ($page['address']['path']  . $house_number);
         } elseif (isset($page['address']['raceway']) and !empty($page['address']['raceway'])) {
            $r_address[] = ($page['address']['raceway'] . $house_number);
         } elseif (isset($page['address']['canal']) and !empty($page['address']['canal'])) {
            $r_address[] = ($page['address']['canal'] . $house_number);
         } elseif (isset($page['address']['industrial']) and !empty($page['address']['industrial'])) {
            $r_address[] = ($page['address']['industrial'] . $house_number);
         }

         // print_r($r_address);

         $before_citystuff=count($r_address);
         // Get whatver its called as the place name
         /* but you need to cover for stuff like 'hamlet', 'city','village' etc ... */
         $place="";
         $continue=true;
         $this->debug( __METHOD__, "simple", 3,print_r($page['address'],true),1);
         foreach($page['address'] as $key => $val) {
            //$this->debug( __METHOD__, "simple", 1, sprintf("%s => %s",$key,$val));
            if (in_array($key, array('town','village', 'city', 'city district','suburb', 'hamlet','locality')) and !empty($continue)) {
               if (isset($page['address']['postcode']) and strtolower($page['address']['country_code'])=='be') {
                  if (!empty($val)) {
                     $r_address[]= sprintf("%s %s",$page['address']['postcode'],$val);
                     $continue=false;
                     break;
                  } 
               } elseif (strtolower($page['address']['country_code'])!='be') {
                     // echo "GLENN\n";
                  if (!empty($val)) {
                     if(isset($page['address']['postcode'])) {
                        $r_address[]= sprintf("%s %s",$page['address']['postcode'],$val);
                        $continue=false;
                        break;
                     } else {
                        $r_address[]= sprintf("%s",$val);
                        break;
                     }
                  } 
               }
            }
         }

         $after_citystuff=count($r_address);
         
         // Get the country, its always there it seems
         if ($after_citystuff > $before_citystuff) {
            // We only have a road so far (maybe).... thats not good.
            if (isset($page['address']['country_code']) and !empty($page['address']['country_code'])) {
               $r_address[] = strtoupper($page['address']['country_code']);
            } else {
               $r_address = array();
            }
         } else {
            $r_address = array();
         }

         $this->debug( __METHOD__, "simple", 2,print_r($r_address,true),1);

         if (is_array($r_address) and count($r_address)>0) {
            $address=implode(', ',$r_address);
         } else {
            $address="";
         }
      } else {
         $this->debug( __METHOD__, "simple", 1, sprintf("Cannot determine streetname."));
         return "";
      }

      $this->debug( __METHOD__, "simple", 3, sprintf("RevGeo = %s|%s result = %s",$this->lat, $this->lon, $this->nominatim_page['curlinfo']['http_code']));

      $newaddress="";
      if ($this->nominatim_page['curlinfo']['http_code']==200) {
         $this->counters['ok_nominatim']++;
         $newaddress=$this->trans->mixed_to_utf8($this->post_filter_address($address));

         // "51.0801183333,4.41619666667, Rumst, BE"
         $filter_out=sprintf("/%s,%s, /",$this->lat,$this->lon);
         $newaddress = preg_replace($filter_out,"", $newaddress);

         $this->debug( __METHOD__, "simple", 2, sprintf("Nominatim encoded is: '%s'.", $newaddress));
      } else {
         /* We are hitting a query problem, just skip this record */
         $this->debug( __METHOD__, "simple", 3, sprintf("Warning, Nominatim says : '%s'.", $this->nominatim_page['curlinfo']['http_code']));
         $this->counters['fail_nominatim']++;
         $this->settings['sleep_nominatim']=$this->settings['sleep_nominatim'] + 500;
      }
      $this->debug( __METHOD__, "simple", 2, sprintf("Newaddress is: '%s'.", $newaddress));
      $this->debug(__METHOD__, "hangup",5);
      return $newaddress;
   }

   public function get_street_name_yandex($lat=null,$lon=null) {
      $this->debug(__METHOD__, "call",5);
      /* NOT FINISHED */

      if(empty($this->settings['use_yandex'])) {
         return "";
      }

      if(isset($lat) and isset($lon)) {
         if(!$this->set_coord($lat,$lon)) {
            $this->debug(__METHOD__, "simple", 0, sprintf("Bad coordinates lat = %s, lon = %s",$lat,$lon));
            return "";
         }
      } elseif(!isset($this->lat) or !isset($this->lon)) {
         $this->debug(__METHOD__, "simple", 0, sprintf("Need to set the coordinates first or pass them as lat/lon to function %s",__FUNCTION__));
         return "";
      }

      $this->debug(__METHOD__, "simple", 1, sprintf("Checking coordinates lat = %s, lon = %s",$this->lat, $this->lon));

      $this->revgeocode_yandex();

      $page = json_decode($this->yandex_page['contents'],true);

      if (!is_array($page)) {
         $this->debug( __METHOD__, "simple", 0,"Problem reading geocode results. Have you geocoded yet?");
         return ""; 
      }

      $this->debug( __METHOD__, "simple", 2,print_r($page,true),1);

      $address="";

      $count = count($page['yandex']);
      /* Geonames doesn't really have a great way to validate the content so lets try it by counting and checking for a field */

      if ($count > 0) {
         /* Trying to extract meaningfull data in most cases is hard work trying */
         $r_address = array();
         if (isset($page['geonames'][0]['countryCode']) and !empty($page['geonames'][0]['countryCode'])) {
            $r_address[] = $page['geonames'][0]['countryCode'];
         }
         $address=implode(', ',$r_address);
         // $address = sprintf("%s %s, %s",$page['geonames'][0]['toponymName'], $page['geonames'][0]['countryCode']);
         // $message = $page['status']['message'];
      } else {
         $this->debug( __METHOD__, "simple", 0,"Error parsing yandex data");
         $this->debug( __METHOD__, "simple", 0,print_r($page,true));
         return "";
      }

      $this->debug( __METHOD__, "simple", 3, sprintf("RevGeo = %s|%s result = %s",$this->lat, $this->lon, $this->yandex_page['curlinfo']['http_code']));

      $newaddress="";
      if ($this->yandex_page['curlinfo']['http_code']==200) {
         $this->counters['ok_yandex']++;
         if (strlen($address)>0) {
            $newaddress=$this->trans->mixed_to_utf8($this->post_filter_address($address));
            $this->debug( __METHOD__, "simple", 2, sprintf("Yandex encoded is: '%s'.", $newaddress));
         } else {
            $this->debug( __METHOD__, "simple", 2, sprintf("Empty address line, check code."));
         }
      } else {
         $this->debug( __METHOD__, "simple", 3, sprintf("Warning, Yandex says : '%s'.", $this->yandex_page['curlinfo']['http_code']));
         $this->counters['fail_yandex']++;
         $this->settings['sleep_yandex']=$this->settings['sleep_yandex'] + 500;
      }
      $this->debug(__METHOD__, "hangup",5);
      return $newaddress;
   }

   public function get_street_name_cloudmade($lat=null,$lon=null) {
      $this->debug(__METHOD__, "call",5);

      if(empty($this->settings['use_cloudmade'])) {
         return "";
      }

      if(isset($lat) and isset($lon)) {
         if(!$this->set_coord($lat,$lon)) {
            $this->debug(__METHOD__, "simple", 0, sprintf("Bad coordinates lat = %s, lon = %s",$lat,$lon));
            return "";
         }
      } elseif(!isset($this->lat) or !isset($this->lon)) {
         $this->debug(__METHOD__, "simple", 0, sprintf("Need to set the coordinates first or pass them as lat/lon to function %s",__FUNCTION__));
         return "";
      }

      $this->debug(__METHOD__, "simple", 1, sprintf("Checking coordinates lat = %s, lon = %s",$this->lat, $this->lon));

      $this->revgeocode_cloudmade();

      //var_dump($this->cloudmade_page['contents']); exit;

      $page = json_decode($this->cloudmade_page['contents'],true);

      if (!is_array($page)) {
         $this->debug( __METHOD__, "simple", 0,"Problem reading geocode results. Have you geocoded yet?");
         return ""; 
      }

      $this->debug( __METHOD__, "simple", 2,print_r($page,true),1);

      $address="";

      if ($page['found'] < 0) {
         $this->debug( __METHOD__, "simple", 0,"Nothing found for coordinates.");
         //var_dump($page); exit(1);
         return ""; 
      }

      // $this->debug( __METHOD__, "simple", 2,sprintf("Count = %d", $page['found']));

      $count = count($page['features']);
      $this->debug( __METHOD__, "simple", 2,sprintf("Count = %d", $count));
      
      $r_address=array();

      if ($count > 0) {
         foreach ($page['features'] as $details ) {
            if (count($details['properties'])) {
               $detail = $details['properties'];
               if (!empty($detail['addr:housenumber'])) { $housenumber=" " . $detail['addr:housenumber']; } else { $housenumber="";}
               if (!empty($detail['addr:postcode'])) { $postcode=$detail['addr:postcode'] . " "; } else { $postcode="";}
               if (!empty($detail['addr:street'])) { $r_address[]=$detail['addr:street'] . $housenumber; }
               if (!empty($detail['addr:city'])) { $r_address[]=$postcode . $detail['addr:city']; }
               if (!empty($detail['addr:country'])) { $r_address[]=$detail['addr:country']; }
            }
         }
         $address=implode(', ',$r_address); 
      } else {
         $this->debug( __METHOD__, "simple", 0,"Error parsing cloudmade data");
         $this->debug( __METHOD__, "simple", 0,print_r($page,true));
         return "";
      }

      /*
      [addr:housenumber] => 420
      [amenity] => bank
      [addr:city] => Eppegem
      [addr:postcode] => 1980
      [atm] => yes
      [osm_id] => 413481350
      [osm_element] => node
      [addr:street] => Brusselsesteenweg
      [addr:country] => BE
      [name] => Fortis
      */

      $this->debug( __METHOD__, "simple", 3, sprintf("RevGeo = %s|%s result = %s",$this->lat, $this->lon, $this->cloudmade_page['curlinfo']['http_code']));

      $newaddress="";

      if ($this->cloudmade_page['curlinfo']['http_code']==200) {
         $this->counters['ok_cloudmade']++;
         if (strlen($address)>0) {
            $newaddress=$this->trans->mixed_to_utf8($this->post_filter_address($address));
            $this->debug( __METHOD__, "simple", 2, sprintf("Cloudmade encoded is: '%s'.", $newaddress));
         } else {
            $this->debug( __METHOD__, "simple", 2, sprintf("Empty address line, check code."));
         }
      } else {
         $this->debug( __METHOD__, "simple", 3, sprintf("Warning, Cloudmade says : '%s'.", $this->cloudmade_page['curlinfo']['http_code']));
         $this->counters['fail_cloudmade']++;
         $this->settings['sleep_cloudmade']=$this->settings['sleep_cloudmade'] + 500;
      }
      $this->debug(__METHOD__, "hangup",5);
      return $newaddress;
   }

   public function get_street_name_all($lat=null,$lon=null) {
      $this->debug(__METHOD__, "call",5);
      /* Get a street from any available engine until you can of run out of engines to consult */

      if(isset($lat) and isset($lon)) {
         if(!$this->set_coord($lat,$lon)) {
            $this->debug(__METHOD__, "simple", 0, sprintf("Bad coordinates lat = %s, lon = %s",$lat,$lon));
            return "";
         }
      } elseif(!isset($this->lat) or !isset($this->lon)) {
         $this->debug(__METHOD__, "simple", 0, sprintf("Need to set the coordinates first or pass them as lat/lon to function %s",__FUNCTION__));
         return "";
      }

      foreach($this->get_engines_available() as $engine ) {
         $this->debug( __METHOD__, "simple", 3, sprintf("Trying -> %s",$engine));
         $geocoder = sprintf('get_street_name_%s',$engine);
         // Calls the variable method
         $result[$engine]=$this->$geocoder();
      }
      //$this->debug(__METHOD__, "simple", 2, $this->engines_available());
      return $result;
   }

   public function get_street_name_any($lat=null,$lon=null,$preferred=array()) {
      $this->debug(__METHOD__, "call",5);
      /* Get a street from any available engine until you can of run out of engines to consult */

      if(isset($lat) and isset($lon)) {
         if(!$this->set_coord($lat,$lon)) {
            $this->debug(__METHOD__, "simple", 0, sprintf("Bad coordinates lat = %s, lon = %s",$lat,$lon));
            return array();
         }
      } elseif(!isset($this->lat) or !isset($this->lon)) {
         $this->debug(__METHOD__, "simple", 0, sprintf("Need to set the coordinates first or pass them as lat/lon to function %s",__FUNCTION__));
         return array();
      }

      // first the preferred
      // Then the rest
      $available = $this->get_engines_available();

      // print_r(array_diff($this->get_engines_available(), $preferred));
      // print_r($preferred);
      
      $engines_in_order = array_merge($preferred , array_diff($available, $preferred));
      foreach($engines_in_order as $engine) {
         $geocoder = sprintf('get_street_name_%s',$engine);
         $this->debug( __METHOD__, "simple", 3, sprintf("Trying -> %s",$geocoder));
         // Calls the variable method
         $result[$engine]=$this->$geocoder();
         if(strlen($result[$engine])) {
            break;
         }
      }
      //$this->debug(__METHOD__, "simple", 2, $this->engines_available());
      return $result;
   }

   public function get_engines_available() {
      $this->debug(__METHOD__, "call",5);
      // $this->debug( __METHOD__, "simple", 2, array_keys($this->settings),1);

      /* Get the state of the geocoding engines from the active settings */
      $engines_enabled=array();
      foreach (array_keys($this->settings) as $key => $val) {
         // $this->debug( __METHOD__, "simple", 2, "Analysing key: " . $val);
         if (preg_match("/^can_use_(.+)/", $val , $matches)) {
            if ($this->settings[$val] > 0 ) {
               //print_r($matches);
               $this->debug( __METHOD__, "simple", 2, "Added engine : " . $val);
               $engines_enabled[]=$matches[1];
            }
         }
      }
      // print_r($this->settings);
      return $engines_enabled;
   }

   public function debug($func, $type="simple", $level, $message = "", $pad_me = 0) {
      /* If the debugger is disabled, retuns without doing anything */

      if (!$this->debug or !isset($func)) {
         return 0;
      }

      $pre="";


      if (strlen($func)==0) {
         $func="Main";
      }

      switch($type){
         case "call":
            $message=""; 
         $pre = sprintf("[%s()] - Called", $func);
         break;
         case "hangup":
            $message=""; 
         $pre = sprintf("[%s()] - Done", $func);
         break;
         case "simple":
            $pre = sprintf("[%s()]", $func);
         break;
         default :
         $pre = sprintf("[%s()]", $func);
         break;
      }

      $DateTime=@date('Y-m-d H:i:s', time());

      if ( $level <= $this->verbose ) {
         $mylvl=NULL;
         switch($level) {
            case 0:   $mylvl ="error"; break;
            case 1:   $mylvl ="core "; break;
            case 2:   $mylvl ="info "; break;
            case 3:   $mylvl ="notic"; break;
            case 4:   $mylvl ="verbs"; break;
            case 5:   $mylvl ="dtail"; break;
            default : $mylvl ="trace"; break;
         }

         $nested=0;
         if (is_array($message)) {
            $pad_length=0;
            foreach ($message as $key=>$val) {
               if(!is_array($val)){
                  $pad_length = (strlen($key) >= $pad_length) ? strlen($key) : $pad_length;
               } else {
                  $nested=0;
               }
            }
                  $nested=0;
            // This is overkill, we can use json pretty print function for this
            if ($nested) {
               if ($type == "stderr") {
                  fwrite(STDERR, $this->json_printable_encode($message,3,true)); 
                  fwrite($this->eol);
               } else {
                  fwrite(STDOUT, $this->json_printable_encode($message,3,true)); 
                  fwrite($this->eol);
               }
               return;
            }

            foreach ($message as $key=>$val) {
               if (!is_array($val)) {
                  /* does the array needs some padding */
                  if($pad_me == 1) {
                     $padded_eq=str_pad($key, $pad_length, ' ' ,STR_PAD_RIGHT);
                     $key_val = sprintf("%s = %s",$padded_eq, $val);
                  } elseif ($pad_me == 2) {
                     $padded_key=str_pad($key, $pad_length, ' ' ,STR_PAD_LEFT);
                     $key_val = sprintf("%s = %s",$padded_key,$val);
                  } else {
                     $key_val = sprintf("%s = %s",$key,$val);
                  }

                  $content = sprintf("%s:[%d]- %s %s%s", $DateTime, $level, $pre, $key_val , $this->eol);
                  if ($type == "stderr") {
                     // or see http://dren.ch/php-print-to-stderr/ and try this below when this doesn't work for YOUR php version
                     // $STDERR = fopen('php://stderr', 'w+');
                     fwrite(STDERR, $content); 
                  } else {
                     fwrite(STDOUT, $content); 
                  }
               }
            }
         } else {
            $lines = explode("\n", trim($message));
            $no_lines = count($lines);

            /*
               if ($no_lines==0) {
               fwrite(STDERR, "\n"); 
               }
             */

            foreach ($lines as $line) {
               $content = sprintf("%s:[%d]- %s %s%s", $DateTime, $level, $pre, $line , $this->eol);
               /* Finally we dump this to stderr or the stdout */
               if ($type == "stderr") {
                  fwrite(STDERR, $content); 
               } else {
                  fwrite(STDOUT, $content); 
               }
            }
         }
      }
      return 1;
   }
}

?>
