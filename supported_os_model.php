<?php

use CFPropertyList\CFPropertyList;

class Supported_os_model extends \Model
{    
    public function __construct($serial = '')
    {
        parent::__construct('id', 'supported_os'); // Primary key, tablename
        $this->rs['id'] = '';
        $this->rs['serial_number'] = $serial;;
        $this->rs['current_os'] = 0;
        $this->rs['highest_supported'] = 0;
        $this->rs['machine_id'] = null;
        $this->rs['last_touch'] = 0;
        $this->rs['shipping_os'] = null;
        $this->rs['model_support_cache'] = null;

        if ($serial) {
            $this->retrieve_record($serial);
        }

        $this->serial_number = $serial;
    }

    // ------------------------------------------------------------------------

    /**
     * Process method, is called by the client
     *
     * @return void
     * @author tuxudo
     **/
    public function process($data)
    {
        // Check if we have data
        if ( ! $data){
            throw new Exception("Error Processing Request: No property list found", 1);
        }

        // Check if we have cached supported OS JSONs
        $queryobj = new Supported_os_model();
        $sql = "SELECT last_touch FROM `supported_os` WHERE serial_number = 'JSON_CACHE_DATA'";
        $cached_data_time = $queryobj->query($sql);

        // Get the current time
        $current_time = time();

        // Check if we have a null result or a week has passed
        if($cached_data_time == null || ($current_time > ($cached_data_time[0]->last_touch + 604800))){

            // Get JSONs from supported_os GitHub
            ini_set("allow_url_fopen", 1);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_URL, 'https://raw.githubusercontent.com/munkireport/supported_os/master/supported_os_data.json');
            $json_result = curl_exec($ch);

            // Check if we got results
            if (strpos($json_result, '"current_os":') === false ){
                print_r("Unable to fetch new JSONs from supported_os GitHub page!! Using local version instead.");
                $json_result = file_get_contents(__DIR__ . '/supported_os_data.json');
                $cache_source = 2;
            } else {
                $cache_source = 1;
            }

            // Delete old cached data
            $this->deleteWhere('serial_number=?', "JSON_CACHE_DATA");

            $json_data = json_decode($json_result);
            $current_os = $json_data->current_os;
            $digits = explode('.', $current_os);
            $mult = 10000;
            $current_os = 0;
            foreach ($digits as $digit) {
                $current_os += $digit * $mult;
                $mult = $mult / 100;
            }

            // Insert new cached data
            $sql = "INSERT INTO `supported_os` (serial_number,current_os,highest_supported,machine_id,last_touch,shipping_os,model_support_cache) 
                    VALUES ('JSON_CACHE_DATA','".$current_os."','".$cache_source."','Do not delete this row','".$current_time."',0,'".$json_result."')";
            $queryobj->exec($sql);
        }

        // Get the cached JSONs from the database
        $sql = "SELECT current_os, model_support_cache FROM `supported_os` WHERE serial_number = 'JSON_CACHE_DATA'";
        $cached_jsons = $queryobj->query($sql)[0]->model_support_cache;
        
        // Decode JSON
        $json_data = json_decode($cached_jsons, true); 
        $highest_os = $json_data['highest'];
        $shipping_os = $json_data['shipping'];
        $most_current_os = $json_data['current_os'];

        // Check if we are processing a plist or not
        if(!is_array($data)){
            $parser = new CFPropertyList();
            $parser->parse($data);     
            $plist = $parser->toArray();
        } else if($data['reprocess']){
            $this->retrieve_record($data['serial_number']);
        }
        
        $model_family = preg_replace("/[^A-Za-z]/", "", $this->rs['machine_id']);
        $model_num = preg_replace("/[^0-9]/", "", $this->rs['machine_id']);

        // Process model ID for highest_supported
        if (array_key_exists($model_family, $highest_os)) {
            // Sort the model ID numbers
            krsort($highest_os[$model_family]);
            
            // Process each model ID number in the model ID family
            foreach($highest_os[$model_family] as $model_check=>$model_os){
                
                // Compare model ID number to supported OS array, highest first
                if ($model_num >= $model_check){
                    // If supported OS is zero, set it to the current OS key from JSON
                    if($model_os == 0){
                        $model_os = $most_current_os;
                    }
                    $this->rs['highest_supported'] = $model_os;
                    break;
                }
            }

        } else {
            // Error out if we cannot locate that machine.
            error_log("Machine model '".$this->rs['machine_id']."' not found in highest supported array.");
            print_r("Machine model '".$this->rs['machine_id']."' not found in highest supported array.");
        }

        // Convert highest_supported to int
        if (isset($this->rs['highest_supported'])) {
            $digits = explode('.', $this->rs['highest_supported']);
            $mult = 10000;
            $this->rs['highest_supported'] = 0;
            foreach ($digits as $digit) {
                $this->rs['highest_supported'] += $digit * $mult;
                $mult = $mult / 100;
            }
        }

        // Set default highest_supported value
        if(empty($this->rs['highest_supported'])){
            $this->rs['highest_supported'] = null;
        }

        
        
        // Process model ID for shipping_os
        if (array_key_exists($model_family, $shipping_os)) {
            // Sort the model ID numbers
            krsort($shipping_os[$model_family]);
            
            // Process each model ID number in the model ID family
            foreach($shipping_os[$model_family] as $model_check=>$model_os){
                
                // Compare model ID number to shipping OS array, highest first
                if ($model_num >= $model_check){
                    $this->rs['shipping_os'] = $model_os;
                    break;
                }
            }

        } else {
            // Error out if we cannot locate that machine.
            error_log("Machine model '".$this->rs['machine_id']."' not found in shipping os array.");
            print_r("Machine model '".$this->rs['machine_id']."' not found in shipping os array.");
        }

        // Convert shipping_os to int
        if (isset($this->rs['shipping_os'])) {
            $digits = explode('.', $this->rs['shipping_os']);
            $mult = 10000;
            $this->rs['shipping_os'] = 0;
            foreach ($digits as $digit) {
                $this->rs['shipping_os'] += $digit * $mult;
                $mult = $mult / 100;
            }
        }

        // Set default shipping_os value
        if(empty($this->rs['shipping_os'])){
            $this->rs['shipping_os'] = null;
        }
        
        
        
        
        // Convert current_os to int
        if (isset($this->rs['current_os']) && !is_array($data)) {
            $digits = explode('.', $this->rs['current_os']);
            $mult = 10000;
            $this->rs['current_os'] = 0;
            foreach ($digits as $digit) {
                $this->rs['current_os'] += $digit * $mult;
                $mult = $mult / 100;
            }
        }
        
        // Set default current_os value
        if(empty($this->rs['current_os'])){
            $this->rs['current_os'] = null;
        }

        $this->current_os = $this->rs['current_os'];
        $this->machine_id = $this->rs['machine_id'];
        $this->last_touch = $this->rs['last_touch'];
        $this->highest_supported = $this->rs['highest_supported'];

        // Save OS gibblets
        $this->save();
        
        // Return something if reprocessing
        if(is_array($data)){
            return true;
        }
    } // End process()
}
