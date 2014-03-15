<?php
class TweetPetsShell extends AppShell
{
    public $uses = array('Pet', 'TwitterUser', 'TwitterUserTimeline');
    
    protected $HttpSocket;
    
    public function __construct()
    { 
        parent::__construct();
        App::uses('HttpSocket', 'Network/Http');
        App::uses('CakeEmail', 'Network/Email');
        $this->HttpSocket = new HttpSocket();
        
        App::uses('PhpReader', 'Configure');
        Configure::config('default', new PhpReader());
        //Load config file for TweetPets
        Configure::load('twitter', 'default');
    }
    
    /**
     * Initial load of all pets at giveshelter.org
     * into local database
     * @return void
     */
    public function insert_all_pets()
    {
        $dchs_pets = $this->get_dchs_pets();
        
        if($dchs_pets){
            $pets_model['Pet'] = $dchs_pets;
            $this->Pet->saveAll($pets_model['Pet']);
        }
    }
    
    /**
     * Update local database. Script runs once every 24 hours.
     * Insert new pets, delete pets no longer listed on giveshelter.org
     * and update current pet information.
     * Only update 5 pets in case giveshelter.org has changed layout which
     * could update every pet with bad information.
     * Email admin a list of new and update pets to verify data integrity.
     * @return void
     */
    public function update_pets()
    {
        $database_pet_ids = $this->Pet->get_pet_ids(); 
        $dchs_pets = $this->get_dchs_pets();
        
        if(is_array($dchs_pets)){
            $dchs_pet_ids = array();
            $dchs_pet_ids = array_map(function($pet){
                            return $pet['pet_id'];
                    }, $dchs_pets);

            //Insert new pets
            $pets_to_add = array();
            foreach($dchs_pets as $dchs_pet){
                $pet_id = $dchs_pet['pet_id'];
                if(!in_array($pet_id, $database_pet_ids)){
                   $pets_to_add[] = $dchs_pet;
                }
            }

            foreach($database_pet_ids as $id){
                //Delete
                if(!in_array($id, $dchs_pet_ids)){
                    $this->Pet->delete_pet($id);
                }
                
                //Update
                foreach($dchs_pets as $dchs_pet){
                    if($dchs_pet['pet_id'] == $id){ 
                        $db_pet = $this->Pet->get_pet($id);
                        //If the dchs pet is already in our db, add id and tweeted_at
                        //in order to sort pets_to_update.
                        if(count(array_diff_assoc($dchs_pet, $db_pet))>0){
                            $dchs_pet['id'] = $db_pet['id']; 
                            $dchs_pet['tweeted_at'] = $db_pet['tweeted_at'];
                            $pets_to_update[] = $dchs_pet;
                        }
                    }
                }
            }
            
            //Sort first by tweeted_at and then by id.
            //We want to update non-tweeted pets first.
            if(isset($pets_to_update)){
                usort($pets_to_update, function($arr1, $arr2){
                    if($arr1['tweeted_at'] < $arr2['tweeted_at']){
                        return -1;
                    }
                    elseif($arr1['tweeted_at'] > $arr2['tweeted_at']){
                        return 1;
                    }
                    else{
                        return $arr1['id'] < $arr2['id'] ? -1 : 1;
                    }
                }); 

                //Only update 5 pets in case of bad data.
                $updated_pets = null;
                if(count($pets_to_update) > 0){
                    for($i=0; $i<count($pets_to_update); $i++){
                        if($i>4) break;
                        unset($pets_to_update[$i]['tweeted_at']);
                        $pet['Pet'] = $pets_to_update[$i];
                        $this->Pet->save($pet['Pet']);
                        $updated_pets[] = $pets_to_update[$i];
                    }
                }
            }
            
            //Email updated pets
            if(isset($updated_pets)){
                $this->_send_email('Updates', $updated_pets);
            }

            //Save and email new pets
            if($pets_to_add){
                $pets_model['Pet'] = $pets_to_add;
                $this->Pet->saveAll($pets_model['Pet']);
                $this->_send_email('Inserts', $pets_to_add);
            }
        }
        
    }
    
    /**
     * Scrape giveshelter.org for pet information.
     * Get all the pets by submitting a $_POST to the site, clean up with regex.
     * @return array 
     */
    public function get_dchs_pets()
    {
        //App::import('Sanitize');
        $params = array('orderBy' => 'ID', 'primaryBreed' => 0, 'primaryBreed_none' => 0, 'primaryBreedcat' => 0,
                        'sex' => 'A', 'ageGroup' => 'All', 'site' => '', 'speciesID' => 0, 'task' => 'apply');
        
        $url = 'https://www.giveshelter.org/index.php?option=com_adoptableanimalsearch&view=adoptableanimalsearchs';
        $results = $this->HttpSocket->post($url, $params); 
                                         
        $sanitize = $this->_strip_tags_f($results);
        $sanitize = preg_replace('/^\s+|\n|\r|\s+$/m', '', $sanitize);
        
        //Sample data
        //ID: 5424002Name: MaxeySpecies: DogPrimaryBreed: Retriever, LabradorSecondaryBreed: Sex: FemaleSN: SpayedSite: Foster ProgramStage: NoFind out more about Maxey
        
        if(!$sanitize) return null;
        
        $data = array();
        $attributes = array('ID', 'Name', 'Species', 'PrimaryBreed', 'SecondaryBreed', 'Gender', 'Age', 'Site', 'Find');
        for($i=0; $i<count($attributes); $i++){
            if($i < count($attributes)-1){
                preg_match_all('/(?<=[\\w\\):]' . $attributes[$i] . ':)[\s\S]*?(?=' . $attributes[$i+1] . ':|Find|\<a)/', $sanitize, $matches);
                $matches = array_shift($matches);
                // When there is no secondary breed, an empty value is fetched as expected, but also junk up to the next look ahead
                // Test with http://www.phpliveregex.com/
                // So, manually go through matches deleting any value after an empty value
                if ($attributes[$i]=='SecondaryBreed') {
                    foreach ($matches as $j => $match) {
                        if (empty($match)) {
                            unset($matches[$j+1]);
                        }
                    }
                    $matches = array_values($matches);
                }
                $data[$attributes[$i]] = $matches;
            }
        }

        $column_name_map = array('ID' => 'pet_id', 'Name' => 'name', 'Species' => 'species',
                                 'PrimaryBreed' => 'primary_breed', 'SecondaryBreed' => 'secondary_breed',
                                 'Gender' => 'gender', 'Age' => 'age', 'Site' => 'site');
        $pets = array();
        foreach($data as $item => $items){
            foreach($items as $key => $value){
                $pets[$key][$column_name_map[$item]] = trim($value);
            }
        }
        
        return $pets;
    }
    
    /**
     * Build text of pet tweet.
     * Find pet with lowest ID that has not been tweeted, or if all tweeted,
     * get earliest tweeted pet.
     * Use Oauth library to post tweet to @dchspets.
     * http://www.neilcrookes.com/2010/04/12/cakephp-oauth-extension-to-httpsocket/
     * Updated for CakePHP 2 by changing App::import to App::uses
     * return void
     */
    public function tweet_pet()
    {
        if(!$pet = $this->Pet->find('first', array('conditions' => array('tweeted_at' => null),
                                              'order' => array('id')))){ 
            $pet = $this->Pet->find('first', array('order' => array('tweeted_at')));
        }
        $pet = array_shift($pet);
        $standard_species = array('cat', 'dog', 'rabbit', 'horse', 'bird', 'reptile');
        if($pet){
            $tweet = strtoupper($pet['species']) . ' - ' . ucwords($pet['name']) . ' is a ';
            $tweet .= strcasecmp($pet['gender'], 'unknown')==0 ? '' : strtolower($pet['gender']) . ' ';
            $tweet .= strtolower($pet['primary_breed']) . ' ';
            $tweet .= strcasecmp($pet['primary_breed'], $pet['secondary_breed'])==0 ? '' : strtolower($pet['secondary_breed']) . ' ';
            $tweet .= in_array(strtolower($pet['species']), $standard_species) ? strtolower($pet['species']) : '';
            $tweet .= ' https://www.giveshelter.org/index.php?option=com_animaldetail&view=animaldetails&id='. $pet['pet_id'];

            App::uses('HttpSocketOauth', 'Vendor');
            $Http = new HttpSocketOauth();
                      
            $request = array(
                'method' => 'POST',
                'uri' => array(
                    'scheme' => 'https',
                    'host' => 'api.twitter.com',
                    'path' => '1.1/statuses/update.json',
                ),
                'auth' => $this->_get_auth(),
                'body' => array(
                    'status' => $tweet,
                ),
            );
            $response = $Http->request($request);
            
            if($response){
                $currentDateTime = (string)date('Y-m-d H:i:s');
                $this->Pet->id = $pet['id'];
                $this->Pet->save(array('tweeted_at' => $currentDateTime));
            }
        }
    }
    
    /**
     * In case of data loss, populate tweeted_at after re-populated database
     * with update_pets
     * return void
     */
    public function set_tweeted_at()
    {
        $twitter_user = $this->TwitterUser->find('all', array('conditions' => array('screen_name' => 'dchspets',
                                                                              'include_entities' => 'false')));
        
        $statuses_count = $twitter_user['TwitterUser']['statuses_count']; 
        $loop_count = (int)ceil($statuses_count/200); 
        
        $max_id = null;
        $tweets = array();

        for($i=0; $i<$loop_count; $i++){
            $conditions =array('include_entities' => 'true',
                               'include_rts' => 'false',
                               'screen_name' => 'dchspets',
                               'trim_user' => 1,
                               'count' => 200);
            if($max_id){
                $conditions['max_id'] = $max_id;
            } 
            $timeline = $this->TwitterUserTimeline->find('all', array('conditions' => $conditions));
            $tweets = array_merge($tweets, $timeline['TwitterUserTimeline']); 
            $last = array_pop($timeline['TwitterUserTimeline']); 
            $last_id = $last['id'];
            $max_id = $last_id-1;
        }
        
        foreach($tweets as $tweet){
            if(isset($tweet['entities']['urls'][0])){
                $url = $tweet['entities']['urls'][0]['expanded_url']; 
                $dchs_id = substr($url, stripos($url, 'id=')+3, strlen($url));
            }
            
            $tweeted_at = date('Y-m-d H:i:s', strtotime($tweet['created_at']));
            
            if($dchs_id){
                $pet = $this->Pet->get_pet($dchs_id);
                if($pet){
                    $this->Pet->id = $pet['id'];
                    $this->Pet->save(array('tweeted_at' => $tweeted_at));
                }
            }
        }
    }
    
    /**
     * Send an email with pets updated and added
     * @param $type string Update or Add
     * @param $pets array pets that were updated or added
     * @return void
     */
    protected function _send_email($type, $pets)
    {
        $email = new CakeEmail();
        $email->config('smtp');
        $email->template('pets', 'default')
                ->emailFormat('html')
                ->from(array(Configure::read('email')))
                ->to(Configure::read('email'))
                ->subject('Tweet Pets DB '. $type)
                ->viewVars(compact('pets', 'type'))
                ->send();
    }
    
    /**
     * Improved strip_tags from
     * http://www.danmorgan.net/programming/php-programming/php-strip_tags-fixed-no-more-missing-data/
     */
    protected function _strip_tags_f($i_html, $i_allowedtags = array(), $i_trimtext = FALSE) {
        if (!is_array($i_allowedtags))
        $i_allowedtags = !empty($i_allowedtags) ? array($i_allowedtags) : array();
        $tags = implode('|', $i_allowedtags);
        
        if (empty($tags))
        $tags = '[a-z]+';
        
        preg_match_all('@</?\s*(' . $tags . ')(\s+[a-z_]+=(\'[^\']+\'|"[^"]+"))*\s*/?>@i', $i_html, $matches);
        
        $full_tags = $matches[0];
        $tag_names = $matches[1];
        
        foreach ($full_tags as $i => $full_tag) {
            if (!in_array($tag_names[$i], $i_allowedtags))
            if ($i_trimtext)
            unset($full_tags[$i]);
            else
            $i_html = str_replace($full_tag, '', $i_html);
        }
        
        return $i_trimtext ? implode('', $full_tags) : $i_html;
    }
    
    /**
     * Values for auth key for OAuth request
     * @return array
     */
    protected function _get_auth()
    {
        return array(
                  'method' => 'OAuth',
                  'oauth_token' => Configure::read('oauth_token'),
                  'oauth_token_secret' => Configure::read('oauth_token_secret'),
                  'oauth_consumer_key' => Configure::read('oauth_consumer_key'),
                  'oauth_consumer_secret' => Configure::read('oauth_consumer_secret')
                  );
    }
}