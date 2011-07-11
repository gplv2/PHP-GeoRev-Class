<?php
/*
$trans = new Latin1UTF8();

$mixed = "MIXED TEXT INPUT";
/
print "Original: ".$mixed;
print "Latin1:   ".$trans->mixed_to_latin1($mixed);
print "UTF-8:    ".$trans->mixed_to_utf8($mixed);
*/

class Latin1UTF8 {

   private $latin1_to_utf8;
   private $utf8_to_latin1;

   public function __construct() {
      for($i=32; $i<=255; $i++) {
         $this->latin1_to_utf8[chr($i)] = utf8_encode(chr($i));
         $this->utf8_to_latin1[utf8_encode(chr($i))] = chr($i);
      }
   }

   public function mixed_to_latin1($text) {
      foreach( $this->utf8_to_latin1 as $key => $val ) {
         $text = str_replace($key, $val, $text);
      }
      return $text;
   }

   public function mixed_to_utf8($text) {
      return utf8_encode($this->mixed_to_latin1($text));
   }
}
?>
