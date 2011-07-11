<?php
/**
 * The class makes it easier to work with memcached servers and provides hints in the IDE like Zend Studio
 * @author Original: Grigori Kochanov http://www.grik.net/
 * @author Modded: Glenn Plas http://byte-consult.be/
 * @version 1 (glenn) # Added checks for memcached servers before using any memcached functions to prevent hard errors
 *
 */
class MCache {
   /**
    * Resources of the opend memcached connections
    * @var array [memcache objects]
    */
   private $mc_servers = array();
   /**
    * Quantity of servers used
    * @var int
    */
   private $mc_servers_count;

   /**
    * Accepts the 2-d array with details of memcached servers
    *
    * @param array $servers
    */
   public function __construct(array $servers){
      if (!is_array($servers)) {
         trigger_error('No memcache servers to connect',E_USER_WARNING);
      }

      foreach($servers as $mserver){
         $server_info=explode(":",$mserver['host']);
         ( $con = @memcache_pconnect($server_info[0], $server_info[1])) && $this->mc_servers[] = $con;
      }

      $this->mc_servers_count = count($this->mc_servers);

      if (!$this->mc_servers_count){
         $this->mc_servers[0]=null;
         return(null);
      }
   }

   public function __destruct() {
      $x = $this->mc_servers_count;
      for ($i = 0; $i < $x; ++$i) {
         $a = $this->mc_servers[$i];
         $this->mc_servers[$i]->close();
      }
   }
   /**
    * Returns the resource for the memcache connection
    *
    * @param string $key
    * @return object memcache
    */
   private function getMemcacheLink($key){
      if ( $this->mc_servers_count <2 ){
         //no servers choice
         return $this->mc_servers[0];
      }
      return $this->mc_servers[(crc32($key) & 0x7fffffff)%$this->mc_servers_count];
   }

   /**
    * Clear the cache
    *
    * @return void
    */
   public function flush() {
      $x = $this->mc_servers_count;
      for ($i = 0; $i < $x; ++$i){
         $a = $this->mc_servers[$i];
         $this->mc_servers[$i]->flush();
      }
   }

   /**
    * Returns the value stored in the memory by it's key
    *
    * @param string $key
    * @return mix
    */
   public function get($key) {
      if ($this->mc_servers_count){
         return $this->getMemcacheLink($key)->get($key);
      }
   }

   /**
    * Store the value in the memcache memory (overwrite if key exists)
    *
    * @param string $key
    * @param mix $var
    * @param bool $compress
    * @param int $expire (seconds before item expires)
    * @return bool
    */
   public function set($key, $var, $compress=1, $expire=0) {
      if ($this->mc_servers_count){
         return $this->getMemcacheLink($key)->set($key, $var, $compress?MEMCACHE_COMPRESSED:null, $expire);
      }
   }
   /**
    * Set the value in memcache if the value does not exist; returns FALSE if value exists
    *
    * @param sting $key
    * @param mix $var
    * @param bool $compress
    * @param int $expire
    * @return bool
    */
   public function add($key, $var, $compress=1, $expire=0) {
      if ($this->mc_servers_count){
         return $this->getMemcacheLink($key)->add($key, $var, $compress?MEMCACHE_COMPRESSED:null, $expire);
      }
   }

   /**
    * Replace an existing value
    *
    * @param string $key
    * @param mix $var
    * @param bool $compress
    * @param int $expire
    * @return bool
    */
   public function replace($key, $var, $compress=1, $expire=0) {
      if ($this->mc_servers_count){
         return $this->getMemcacheLink($key)->replace($key, $var, $compress?MEMCACHE_COMPRESSED:null, $expire);
      }
   }
   /**
    * Delete a record or set a timeout
    *
    * @param string $key
    * @param int $timeout
    * @return bool
    */
   public function delete($key, $timeout=0) {
      if ($this->mc_servers_count){
         return $this->getMemcacheLink($key)->delete($key, $timeout);
      }
   }
   /**
    * Increment an existing integer value
    *
    * @param string $key
    * @param mix $value
    * @return bool
    */
   public function increment($key, $value=1) {
      if ($this->mc_servers_count){
         return $this->getMemcacheLink($key)->increment($key, $value);
      }
   }

   /**
    * Decrement an existing value
    *
    * @param string $key
    * @param mix $value
    * @return bool
    */
   public function decrement($key, $value=1) {
      if ($this->mc_servers_count){
         return $this->getMemcacheLink($key)->decrement($key, $value);
      }
   }

   public function getServerCount() {
      return $this->mc_servers_count;  
   }
   //class end
}

?>
