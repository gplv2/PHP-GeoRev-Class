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
 */

Class GeoRev {
   // Defaults, base stuff we can't do without
   private $settings= array( 
         'debug' => 1,
         'verbose' => 3,
         'use_yahoo' => true,
         'use_bing' => true,
         'use_geonames' => true,
         'use_nominatim' => true,
         'use_google' => true,
         'use_google_v3' => false,
         'sleep_bing' => '2000',
         'sleep_yahoo' => '2000',
         'sleep_google' => '2000',
         'sleep_geonames' => '2000',
         'sleep_nominatim' => '2000',
         'mc_compress' => 1,
         'mc_expire' => 120
         );

   // This kinda maps what belongs to who
   private $service_variable_map = array (
         'bing' => array('timer_name' => 'bi_timer','sleep_setting' => 'sleep_bing'),
         'yahoo' => array('timer_name' => 'ya_timer','sleep_setting' => 'sleep_yahoo'),
         'google' => array('timer_name' => 'go_timer','sleep_setting' => 'sleep_google'),
         'geonames' => array('timer_name' => 'ge_timer','sleep_setting' => 'sleep_geonames'),
         'nominatim' => array('timer_name' => 'no_timer','sleep_setting' => 'sleep_nominatim')
         );

   // Keep track of what works
   private $engine_states;

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

   // Raw return cache of the page, its' ok these are public
   public $google_page; 
   public $yahoo_page; 
   public $bing_page; 
   public $geonames_page; 
   public $nominatim_page; 

   // Dev aid
   public $debug;
   public $verbose;

   public function __construct($conf_settings) {
      /* test if we are called from the CLI */
      if (defined('STDIN')) {
         $this->eol="\n";
      } else {
         $this->eol="<BR/>";
      }

      /* Prepare this one already */
      $this->trans= new Latin1UTF8();


      /* Determine the state of the engines from the settings */
      $auto_settings['can_use_google_v3'] = !empty($conf_settings['google_premierid']) ? 1 : 0;
      $auto_settings['can_use_google']    = !empty($conf_settings['use_google'])       ? 1 : 0;
      $auto_settings['can_use_yahoo']     = !empty($conf_settings['key_yahoo'])        ? 1 : 0;
      $auto_settings['can_use_bing']      = !empty($conf_settings['key_bing'])         ? 1 : 0;
      $auto_settings['can_use_geonames']  = !empty($conf_settings['key_geonames'])     ? 1 : 0;
      $auto_settings['can_use_nominatim'] = !empty($conf_settings['key_nominatim'])    ? 1 : 0;

      // Merge the class defaults with the settings
      $this->settings = array_merge($this->settings, $conf_settings);
      // Merge the autosettings with the settings
      $this->settings = array_merge($this->settings, $auto_settings);

      // Set the correct debug values
      $this->verbose=$this->settings['verbose'];
      $this->debug=$this->settings['debug'];

      $this->debug(__METHOD__, "simple" , 2, sprintf("Status geocode engines"));
      $this->debug(__METHOD__, "simple" , 2, $auto_settings,1);

      // Record the engine states for later
      $this->engine_states=$auto_settings;

      // Check for engine availability right off the bat before going further, we can't do anything meaningfull without atleast 1
      if (!$this->engines_available()) {
         $this->debug(__METHOD__, "simple", 0, sprintf("No geocoding engine available, check config file for key / parameter settings"));
         exit;
      }

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
            // var_dump($this->MC);
            if (!$this->MC->getServerCount()) {
               $this->debug(__METHOD__, "simple" , 2, sprintf("No Memcache server candidates are working"));
               unset($this->MC);
            } else {
               $this->debug(__METHOD__, "simple" , 2, sprintf("%d Memcache server candidates available, testing ...",$this->MC->getServerCount()));

               // Create test data
               $test_val = sprintf("%s%s", md5(json_encode($conf_settings['cacheservers'])),time());
               $test_key = "GEOREVTEST";

               // Set and get a known value
               $this->MC->set($test_key, $test_val, $compress=1, $expire=60);
               $result = $this->MC->get($test_key);

               if (strcmp($result, $test_val) == 0) {
                  $this->debug(__METHOD__, "simple" , 2, sprintf("Memcache seems to be working."));
                  $memcached_counters= array ('memc_get','memc_set','memc_hit','memc_miss');
                  $this->register_counter($memcached_counters,0);
               } else {
                  // Memcached doesn't seem to work
                  $this->debug(__METHOD__, "simple" , 2, sprintf("Disabling Memcache since its not working."));
                  unset($this->MC);
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
            'yahoo_fail',
            'bing_fail',
            'geonames_fail',
            'google_fail',
            'nominatim_fail',
            'bing_ok',
            'google_ok',
            'yahoo_ok',
            'geonames_ok',
            'nominatim_ok'
            );
      $this->register_counter($init_counters,0);

      // timers for the services
      $init_timers = array ('go_timer','ya_timer','bi_timer','ge_timer','no_timer');
      $this->register_counter($init_timers,microtime(true));

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

      if (empty($lat) OR empty($lon)) {
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


   public function revgeocode_all () {
      $this->debug(__METHOD__, "call",5);
      /* calc bing timeout */
      if(!empty($this->settings['use_bing'])) {
         $interval_since_last = (microtime(true) - $this->counters['bi_timer'] ) * 1000000; # This is seconds and we are working in microseconds
            $minimum_sleep = $this->settings['sleep_bing']*1000;
         if ($interval_since_last < $minimum_sleep) {
            $bing_wait = $minimum_sleep - $interval_since_last;
            //$this->debug( __METHOD__, "simple", 0, array ('now'=> microtime(true), 'bi_timer'=> $this->counters['bi_timer'], 'interval'=> $interval_since_last, 'min'=> $minimum_sleep, 'wait'=> $bing_wait), 2);
         } else {
            $bing_wait = 0;
         }
      }

      /* calc yahoo timeout */
      if(!empty($this->settings['use_yahoo'])) {
         $interval_since_last = (microtime(true) - $this->counters['ya_timer'] ) * 1000000; # This is seconds and we are working in microseconds so multiply
            $minimum_sleep = $this->settings['sleep_yahoo']*1000;
         if ($interval_since_last < $minimum_sleep) {
            $yahoo_wait = $minimum_sleep - $interval_since_last;
         } else {
            $yahoo_wait = 0;
         }
      }
      $this->debug(__METHOD__, "hangup",5);
   }

   private function revgeocode_google () {
      $this->debug(__METHOD__, "call",5);
      $tag='google';

      // The memcache key
      $mckey=sprintf("RG%s_%s|%s",strtoupper(substr($tag,0,2)), $this->float_to_small($this->lat), $this->float_to_small($this->lon));

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

      if($this->counters['google_fail']>$this->settings['google_max_fail'] OR (!$this->settings['can_use_google'] AND !$this->settings['can_use_google_v3'])) {
         $this->settings['use_google']=0;
         return "";
      }

      if (!empty($this->settings['dryrun'])) {
         $this->debug(__METHOD__, "simple",5,"Dryrun active, not doing encode now.");
         return "";
      }

      if(empty($this->settings['use_google'])) {
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
         $url =$baseurl . sprintf("/maps/geo?q=%s,%s&output=json&key=%s&sensor=false", $this->lat, $this->lon, $this->settings['key_google']);
         $this->debug(__METHOD__, "simple" , 5, sprintf("Google v2"));
      }

      //$this->debug( __METHOD__, "simple", 3, sprintf("Geocoding url '%s'", $url));
      $this->counters['hit_google']++;

      /* Do it with curl */
      $ch = curl_init($url);

      // $post_arr = array(), 'cmd' => "startstop", 'range' => $range);
      // curl_setopt($ch, CURLOPT_POSTFIELDS, $post_arr);

      curl_setopt($ch, CURLOPT_RETURNTRANSFER , true);
      $server_output = curl_exec($ch);
      $curlinfo = curl_getinfo($ch);
      curl_close($ch);

      $this->debug( __METHOD__, "simple", 5, $curlinfo,1);

      if ($curlinfo['http_code']!=200) {
         $this->counters['google_fail']++;
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
      $mckey=sprintf("RG%s_%s|%s",strtoupper(substr($tag,0,2)), $this->float_to_small($this->lat), $this->float_to_small($this->lon));

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

      if($this->counters['bing_fail']>$this->settings['bing_max_fail'] OR !$this->settings['can_use_bing']) {
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
         return "";
      }

      $this->throttle_service($tag);
      $this->debug( __METHOD__, "simple", 2, sprintf("Encoding with Bing"));

      $baseurl = "http://dev.virtualearth.net/REST/v1/Locations/%s,%s?o=json&key=%s";
      $url = sprintf($baseurl,$this->lat,$this->lon,$this->settings['key_bing']);

      // $this->debug( __METHOD__, "simple", 3, sprintf("Geocoding url '%s'", $url));
      $this->counters['hit_bing']++;

      /* Do it with curl */
      $ch = curl_init($url);

      // $post_arr = array(), 'cmd' => "startstop", 'range' => $range);
      // curl_setopt($ch, CURLOPT_POSTFIELDS, $post_arr);

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
         $this->counters['bing_fail']++;
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

      $this->bing_page = array ('curlinfo' => $curlinfo, 'contents' => $contents);
      // Store in the cache if we can
      if(isset($this->MC)){
         $this->debug(__METHOD__, "simple",1,"Saving in cache");
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
      $mckey=sprintf("RG%s_%s|%s",strtoupper(substr($tag,0,2)), $this->float_to_small($this->lat), $this->float_to_small($this->lon));

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

      if($this->counters['yahoo_fail']>$this->settings['yahoo_max_fail'] OR !$this->settings['can_use_yahoo']) {
         $this->settings['use_yahoo']=0;
         return "";
      }

      if (!empty($this->settings['dryrun'])) {
         $this->debug(__METHOD__, "simple",5,"Dryrun active, not doing encode now.");
         return "";
      }

      if(empty($this->settings['use_yahoo'])) {
         return "";
      }
      $this->throttle_service($tag);
      $this->debug( __METHOD__, "simple", 2, sprintf("Encoding with Yahoo placefinder"));

      /*
         We need to url sign this request, like this example: 
http://where.yahooapis.com/geocode?q=%1$s,+%2$s&gflags=R&appid=[yourappidhere]
       */

      $baseurl = "http://where.yahooapis.com/geocode?q=";
      $url = $baseurl . $this->lat .",".$this->lon."&gflags=R&flags=J&appid=" . $this->settings['key_yahoo'];

      // $this->debug( __METHOD__, "simple", 0, sprintf("Geocoding url '%s'", $url));
      $this->counters['hit_yahoo']++;

      /* Do it with curl */
      $ch = curl_init($url);

      // $post_arr = array(), 'cmd' => "startstop", 'range' => $range);
      // curl_setopt($ch, CURLOPT_POSTFIELDS, $post_arr);

      curl_setopt($ch, CURLOPT_RETURNTRANSFER , true);
      $server_output = curl_exec($ch);
      $curlinfo = curl_getinfo($ch);
      curl_close($ch);

      $this->debug( __METHOD__, "simple", 5, $curlinfo,1);


      if ($curlinfo['http_code']!=200) {
         $this->counters['yahoo_fail']++;
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
         $this->debug(__METHOD__, "simple",1,"Saving in cache");
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
      $mckey=sprintf("RG%s_%s|%s",strtoupper(substr($tag,0,2)), $this->float_to_small($this->lat), $this->float_to_small($this->lon));

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

      if($this->counters['geonames_fail']>$this->settings['geonames_max_fail'] OR !$this->settings['can_use_geonames']) {
         $this->settings['use_geonames']=0;
         return "";
      }

      if (!empty($this->settings['dryrun'])) {
         $this->debug(__METHOD__, "simple",5,"Dryrun active, not doing encode now.");
         return "";
      }


      if(empty($this->settings['use_geonames'])) {
         $this->debug( __METHOD__, "simple", 0, sprintf("Bail out"));
         return "";
      }

      $this->throttle_service($tag);
      $this->debug( __METHOD__, "simple", 2, sprintf("Encoding with GeoNames JSON API"));

      $baseurl = "http://api.geonames.org/findNearbyPlaceNameJSON?lat=%s&lng=%s&username=%s&style=full";
      $url = sprintf($baseurl,$this->lat,$this->lon,$this->settings['key_geonames']);
      // $this->debug( __METHOD__, "simple", 3, sprintf("Geocoding url '%s'", $url));
      $this->counters['hit_geonames']++;

      /* Do it with curl */
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER , true);
      $server_output = curl_exec($ch);
      $curlinfo = curl_getinfo($ch);
      curl_close($ch);

      $this->debug( __METHOD__, "simple", 5, $curlinfo,1);

      if ($curlinfo['http_code']!=200) {
         $this->counters['geonames_fail']++;
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
         $this->debug(__METHOD__, "simple",1,"Saving in cache");
         $this->MC->set($mckey, $this->geonames_page, $this->settings['mc_compress'], $this->settings['mc_expire']);
         $this->counters['memc_set']++;
      }
      $this->counters['ge_timer']=microtime(true);
      return 1;
   }


   private function revgeocode_nominatim () {
      // http://open.mapquestapi.com/nominatim/#reverse
      $this->debug(__METHOD__, "call",5);
      $tag='nominatim';

      // The memcache key
      $mckey=sprintf("RG%s_%s|%s",strtoupper(substr($tag,0,2)), $this->float_to_small($this->lat), $this->float_to_small($this->lon));

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

      if($this->counters['nominatim_fail']>$this->settings['nominatim_max_fail'] OR !$this->settings['can_use_nominatim']) {
         $this->settings['use_nominatim']=0;
         return "";
      }

      if (!empty($this->settings['dryrun'])) {
         $this->debug(__METHOD__, "simple",5,"Dryrun active, not doing encode now.");
         return "";
      }

      if(empty($this->settings['use_nominatim'])) {
         return "";

      }
      $this->throttle_service($tag);
      $this->debug( __METHOD__, "simple", 2, sprintf("Encoding with Nominatim"));

      $baseurl = "http://open.mapquestapi.com/nominatim/v1/reverse?format=json&lat=%s&lon=%s&email=%s";
      $url = sprintf($baseurl,$this->lat,$this->lon,$this->settings['key_nominatim']);

      // $this->debug( __METHOD__, "simple", 3, sprintf("Geocoding url '%s'", $url));
      $this->counters['hit_nominatim']++;

      /* Do it with curl */
      $ch = curl_init($url);

      // $post_arr = array(), 'cmd' => "startstop", 'range' => $range);
      // curl_setopt($ch, CURLOPT_POSTFIELDS, $post_arr);

      curl_setopt($ch, CURLOPT_RETURNTRANSFER , true);
      $server_output = curl_exec($ch);
      $curlinfo = curl_getinfo($ch);
      curl_close($ch);

      $this->debug( __METHOD__, "simple", 5, $curlinfo,1);

      if ($curlinfo['http_code']!=200) {
         $this->counters['nominatim_fail']++;
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
         $this->debug(__METHOD__, "simple",1,"Saving in cache");
         $this->MC->set($mckey, $this->nominatim_page, $this->settings['mc_compress'], $this->settings['mc_expire']);
         $this->counters['memc_set']++;
      }
      $this->counters['no_timer']=microtime(true);
      return 1;
   }


   // Helper functions
   private function post_filter_address($address) {
      if (empty($address)) {
         return "";
      }
      $this->debug(__METHOD__, "call",5);
      $location = preg_replace("/ Belgium/"," BE", $address);
      $location = preg_replace("/ The Netherlands/"," NL", $location);
      $location = preg_replace("/ Netherlands/"," NL", $location);
      $location = preg_replace("/ France/"," FR", $location);
      $location = preg_replace("/ Ghent/"," Gent", $location);
      $location = preg_replace("/ Antwerp/"," Antwerpen", $location);
      $location = preg_replace("/ Arrondissement/","", $location);
      $location = preg_replace("/Province/","", $location);
      $newaddress=trim($location);
      $this->debug(__METHOD__, "hangup",5);
      return($newaddress);
   }

   public function json_printable_encode($in, $indent = 3, $from_array = false) {
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


   // Google V3 functions
   private function encode_base64_url_safe($value) {
      //$this->debug(__METHOD__, "call",5);
      return str_replace(array('+', '/'), array('-', '_'), base64_encode($value));
   }

   private function decode_base64_url_safe($value) {
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
   private function my_chomp(&$string) {
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
   private function small_to_float($LatitudeSmall) {
      if(($LatitudeSmall>0)&&($LatitudeSmall>>31)) {
         $LatitudeSmall=-(0x7FFFFFFF-($LatitudeSmall&0x7FFFFFFF))-1;
      }
      return (float)$LatitudeSmall/(float)600000;
   }

   private function float_to_small($LongitudeFloat) {
      $Longitude=round((float)$LongitudeFloat*(float)600000);
      if($Longitude<0) { 
         $Longitude+=0xFFFFFFFF; 
      }
      return $Longitude;
   }

   private function engines_available($specific_engine=null){
      $any_around=0;
      if (!isset($specific_engine)) {
         foreach($this->engine_states as $key => $val) {
            if ($val>0) {
               $any_around=1;
               break;
            } 
         }
      } else {
         $key=sprintf("can_use_%s",$specific_engine);
         if ($this->engine_states[$key]==1){
            $any_around=1;
         }
      }
      return $any_around;
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
      $this->debug( __METHOD__, "simple", 4, sprintf("Minsleep for %s is %d",$service_name, $minimum_sleep));
# microtime is a float in seconds and we are working in microseconds so adjust
      $interval_since_last = (microtime(true) - $this->counters[$target_timer] ) * 1000000; 
      $this->debug( __METHOD__, "simple", 3, sprintf("Interval since last is %d",$interval_since_last));

# can we issue the request yet of do we need some sleep
      if ($interval_since_last < $minimum_sleep) {
         $wait = $minimum_sleep - $interval_since_last;
         //$this->debug( __METHOD__, "simple", 0, array ('now'=> microtime(true), 'bi_timer'=> $this->counters['bi_timer'], 'interval'=> $interval_since_last, 'min'=> $minimum_sleep, 'wait'=> $wait), 2);
         $this->debug( __METHOD__, "simple", 4, sprintf("Sleeping for %d us",$wait));
         usleep($wait);
         // $spare = $interval_since_last - $minimum_sleep;
         // $this->debug( __METHOD__, "simple", 0, sprintf("Not waiting , spare %s time is %s us",$service_name, $spare));
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

      $this->revgeocode_google();

      $page = json_decode($this->google_page['contents'],true);

      if (!is_array($page)) {
         $this->debug( __METHOD__, "simple", 0,"Problem reading geocode results. Have you geocoded yet?");
         return ""; 
      }

      $this->debug( __METHOD__, "simple", 1,print_r($page,true),1);

      // We can easily perform more in depth checks with google
      $status = $page['Status']['code'];
      if (strcmp($status, "200") == 0) {
         $this->counters['google_ok']++;
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
         $newaddress=$this->trans->mixed_to_latin1($this->post_filter_address($newaddress));

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

      $this->revgeocode_bing();

      $page = json_decode($this->bing_page['contents'],true);

      if (!is_array($page)) {
         $this->debug( __METHOD__, "simple", 0,"Problem reading geocode results. Have you geocoded yet?");
         return ""; 
      }

      $this->debug( __METHOD__, "simple", 1,print_r($page,true),1);

      $count = count($page['resourceSets']);

      if($count>0) {
         $address = $page['resourceSets'][0]['resources'][0]['address']['formattedAddress'];
      }

      //logtrace(2, print_r($page,true));
      //logtrace(2, print_r($page,true));
      $this->debug( __METHOD__, "simple", 3, sprintf("RevGeo = %s|%s result = %s",$this->lat, $this->lon, $this->bing_page['curlinfo']['http_code']));

      $newaddress="";

      if ($this->bing_page['curlinfo']['http_code']==200) {
         $this->counters['bing_ok']++;
         $newaddress=$this->trans->mixed_to_latin1($this->post_filter_address($address));

         // "51.0801183333,4.41619666667, Rumst, BE"
         $filter_out=sprintf("/%s,%s, /",$this->lat,$this->lon);
         $newaddress = preg_replace($filter_out,"", $newaddress);

         if (strlen($newaddress)<1) {
            $newaddress=NULL;
         }
         $this->debug( __METHOD__, "simple", 2, sprintf("Bing encoded is: '%s'.", $newaddress));
      } elseif ($status>0){
         /* We are hitting a query problem, just skip this record */
         $this->debug( __METHOD__, "simple", 3, sprintf("Warning, Bing says : '%s'.", $message));
         $this->counters['bing_fail']++;
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

      $this->revgeocode_yahoo();

      $page = json_decode($this->yahoo_page['contents'],true);

      if (!is_array($page)) {
         $this->debug( __METHOD__, "simple", 0,"Problem reading geocode results. Have you geocoded yet?");
         return ""; 
      }

      $this->debug( __METHOD__, "simple", 1,print_r($page,true),1);

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
         $this->counters['yahoo_ok']++;
         // Successful geocode
         $newaddress=trim($address);
         $newaddress=$this->trans->mixed_to_latin1($newaddress);
         /* When yahoo doesn't know the street for sure it just returns the coordinates it received 
          * and places this in the field where we otherwise get our streetname from , isn't that great.  So we need to filter these out */
         /* eg: "51.0801183333,4.41619666667, Rumst, BE" as streetname */
         $filter_out=sprintf("/%s,%s, /",$this->lat,$this->lon);
         $newaddress = preg_replace($filter_out,"", $newaddress);

         if (strlen($newaddress)<1) {
            $newaddress=NULL;
         }
         $this->debug( __METHOD__, "simple", 2, sprintf("Yahoo encoded is: '%s'.", $newaddress));
      } elseif ($status>0){
         /* We are hitting a query problem, just skip this record */
         $this->debug( __METHOD__, "simple", 3, sprintf("Warning, Yahoo says : '%s'.", $message));
         $this->counters['yahoo_fail']++;
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

      $this->revgeocode_geonames();

      $page = json_decode($this->geonames_page['contents'],true);

      if (!is_array($page)) {
         $this->debug( __METHOD__, "simple", 0,"Problem reading geocode results. Have you geocoded yet?");
         return ""; 
      }

      $this->debug( __METHOD__, "simple", 1,print_r($page,true),1);

      $address="";

      $count = count($page['geonames']);
      /* Geonames doesn't really have a great way to validate the content so lets try it by counting and checking for a field */

      if ($count > 0) {
         /* Trying to extract meaningfull data in most cases is hard work trying */
         $r_address = array();
         if (isset($page['geonames'][0]['adminName4']) and !empty($page['geonames'][0]['adminName4'])) {
            if (isset($page['geonames'][0]['name']) and !empty($page['geonames'][0]['name'])) {
               $r_address[] = $page['geonames'][0]['name'];
            } else {
               $r_address[] = $page['geonames'][0]['adminName4'];
            }
         }

         if (isset($page['geonames'][0]['alternateNames']) and is_array($page['geonames'][0]['alternateNames'])) {
            if (isset($page['geonames'][0]['alternateNames']['name']) and isset($page['geonames'][0]['alternateNames']['lang'])=='post') {
# This is really the postal code
               $r_address[] = $page['geonames'][0]['alternateNames']['name'];
            }
         }

         if (isset($page['geonames'][0]['adminName3']) and !empty($page['geonames'][0]['adminName3'])) {
            $r_address[] = $page['geonames'][0]['adminName3'];
         }
         if (isset($page['geonames'][0]['adminName2']) and !empty($page['geonames'][0]['adminName2'])) {
            $r_address[] = $page['geonames'][0]['adminName2'];
         }
         if (isset($page['geonames'][0]['adminName1']) and !empty($page['geonames'][0]['adminName1'])) {
            $r_address[] = $page['geonames'][0]['adminName1'];
         }
         if (isset($page['geonames'][0]['countryCode']) and !empty($page['geonames'][0]['countryCode'])) {
            $r_address[] = $page['geonames'][0]['countryCode'];
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
         $this->counters['geonames_ok']++;
         if (strlen($address)>0) {
            $newaddress=$this->trans->mixed_to_latin1($this->post_filter_address($address));
            $this->debug( __METHOD__, "simple", 2, sprintf("Geonames encoded is: '%s'.", $newaddress));
         } else {
            $this->debug( __METHOD__, "simple", 2, sprintf("Empty address line, check code."));
         }
      } else {
         $this->debug( __METHOD__, "simple", 3, sprintf("Warning, Geonames says : '%s'.", $this->geonames_page['curlinfo']['http_code']));
         $this->counters['geonames_fail']++;
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

      $this->revgeocode_nominatim();

      $page = json_decode($this->nominatim_page['contents'],true);

      if (!is_array($page)) {
         $this->debug( __METHOD__, "simple", 0,"Problem reading geocode results. Have you geocoded yet?");
         return ""; 
      }

      $this->debug( __METHOD__, "simple", 1,print_r($page,true),1);

      /* Nominatim is much more straightforward to decode */
      if (isset($page['address'])) {
         $r_address = array();
         if (isset($page['address']['road']) and !empty($page['address']['road'])) {
            $r_address[] = $page['address']['road'];
         }
         /* but you need to cover for stuff like 'hamlet', 'city','village' etc ... */
         if (isset($page['address']['postcode']) and !empty($page['address']['postcode'])) {
            $where_is_i="";
            if (!empty($page['address']['city'])) {
               $where_is_i=$page['address']['city'];
            } elseif (!empty($page['address']['hamlet'])) {
               $where_is_i=$page['address']['hamlet'];
            } elseif (!empty($page['address']['village'])) {
               $where_is_i=$page['address']['village'];
            } else {
               $where_is_i=$page['address'][1];
            }
            $r_address[] = $page['address']['postcode'] . " " . $where_is_i;
         } else {
            if (!empty($page['address']['suburb'])) {
               $r_address[] = $page['address']['suburb'];
            } elseif (!empty($page['address']['city'])) {
               $r_address[] = $page['address']['city'];
            } else {
               $r_address[] = $page['address']['state'];
            }
         }
         if (isset($page['address']['country_code']) and !empty($page['address']['country_code'])) {
            $r_address[] = strtoupper($page['address']['country_code']);
         }
         $address=implode(', ',$r_address);
      } else {
         $this->debug( __METHOD__, "simple", 1, sprintf("Cannot determine streetname."));
         return "";
      }

      $this->debug( __METHOD__, "simple", 3, sprintf("RevGeo = %s|%s result = %s",$this->lat, $this->lon, $this->nominatim_page['curlinfo']['http_code']));

      $newaddress="";
      if ($this->nominatim_page['curlinfo']['http_code']==200) {
         $this->counters['nominatim_ok']++;
         $newaddress=$this->trans->mixed_to_latin1($this->post_filter_address($address));

         // "51.0801183333,4.41619666667, Rumst, BE"
         $filter_out=sprintf("/%s,%s, /",$this->lat,$this->lon);
         $newaddress = preg_replace($filter_out,"", $newaddress);

         $this->debug( __METHOD__, "simple", 2, sprintf("Nominatim encoded is: '%s'.", $newaddress));
      } else {
         /* We are hitting a query problem, just skip this record */
         $this->debug( __METHOD__, "simple", 3, sprintf("Warning, Nominatim says : '%s'.", $this->nominatim_page['curlinfo']['http_code']));
         $this->counters['nominatim_fail']++;
         $this->settings['sleep_nominatim']=$this->settings['sleep_nominatim'] + 500;
      }
      $this->debug(__METHOD__, "hangup",5);
      return $newaddress;
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
