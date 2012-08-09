<?php
class TweetPetsShell extends AppShell
{
    public $uses = array('Pet');
    
    protected $HttpSocket;
    
    public function __construct(){ 
        parent::__construct();
        //App::import('Lib', 'Twitter');
        //$this->twitter = new Twitter();
        App::uses('HttpSocket', 'Network/Http');
        $this->HttpSocket = new HttpSocket();
    }
    
    public function main()
    {
        $this->out(extension_loaded('openssl'));
    }
    
    public function hey_there()
    {
        $this->out('Hey there ' . $this->args[0]);
    }
    
    public function insert_all_pets(){

        $dchs_pets = $this->get_dchs_grouped_data(); 
        $pets = array();
        $pets_model = array();
        for($i=0; $i<count($dchs_pets['ID']); $i++){  
            $pets[] = $this->make_pet($dchs_pets, $i);
        }
        $this->out(var_dump($pets));
        if($pets){
            $pets_model['Pet'] = $pets;
            //var_dump($pets_model['Pet']);
            $this->Pet->saveAll($pets_model['Pet']);
        }
    }
    
    public function update_pets()
    {
        $database_pet_ids = $this->Pet->get_pet_ids(); 
        $dchs_pets = $this->get_dchs_grouped_data(); 
        if($dchs_pets){
            $dchs_pet_ids = $dchs_pets['ID'];
            
            //Delete pets
            foreach($database_pet_ids as $id){ 
                if(!in_array($id, $dchs_pet_ids)){ 
                    $this->Pet->delete_pet($id);
                }
            }
            
            //Compare existing pets
            //and find new pets
            $pets_to_add = array();
            $pets_to_update = array();
            foreach($dchs_pet_ids as $key => $id){
                $dchs_pet = $this->make_pet($dchs_pets, $key);
                $db_pet = $this->Pet->get_pet($dchs_pet['pet_id']);
                //If the dchs pet is already in our db, add id and tweeted_at
                //in order to sort pets_to_update.
                if($db_pet){ 
                    if(count(array_diff_assoc($dchs_pet, $db_pet))>0){
                        $dchs_pet['id'] = $db_pet['id'];
                        $dchs_pet['tweeted_at'] = $db_pet['tweeted_at'];
                        $pets_to_update[] = $dchs_pet;
                    }
                }
                //Make array of new pets
                if(!in_array($id, $database_pet_ids)){
                    $pets_to_add[] = $dchs_pet;
                }
            }
            //Sort first by tweeted_at and then by id.
            //We want to update non-tweeted pets first.
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
            for($i=0; $i<5; $i++){
                unset($pets_to_update[$i]['tweeted_at']);
                $pet['Pet'] = $pets_to_update[$i];
                $this->Pet->save($pet['Pet']);
                $updated_pets[] = $pets_to_update[$i];
            }

            //Email updated pets
            if($updated_pets){
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
    
    public function make_pet($data, $array_id = null){
        if($array_id !== null){
            return array('pet_id' => trim($data['ID'][$array_id]), 'name' => trim($data['Name'][$array_id]),
                            'species' => trim($data['Species'][$array_id]), 'primary_breed' => trim($data['PrimaryBreed'][$array_id]),
                            'secondary_breed' => trim($data['SecondaryBreed'][$array_id]), 'gender' => trim($data['Gender'][$array_id]),
                            'age' => trim($data['Age'][$array_id]), 'site' => trim($data['Site'][$array_id]));
        }
    }
    
    public function get_dchs_grouped_data(){

        App::import('Sanitize');
        $params = array('orderBy' => 'ID', 'primaryBreed' => 0, 'primaryBreed_none' => 0, 'primaryBreedcat' => 0,
                        'sex' => 'A', 'ageGroup' => 'All', 'site' => '', 'speciesID' => 0, 'task' => 'apply');
        $results = $this->HttpSocket->post('http://www.giveshelter.org/component/adoptableanimalsearch/index.php?option=com_adoptableanimalsearch',
                                    $params); 

        $sanitize = $this->_strip_tags_f($results); 
        //var_dump($sanitize); exit;
        //ID: 5424002Name: MaxeySpecies: DogPrimaryBreed: Retriever, LabradorSecondaryBreed: Sex: FemaleSN: SpayedSite: Foster ProgramStage: NoFind out more about Maxey
        
        if(!$sanitize) return null;
        
        $data = array();
        $attributes = array('ID', 'Name', 'Species', 'PrimaryBreed', 'SecondaryBreed', 'Gender', 'Age', 'Site', 'Find');
        for($i=0; $i<count($attributes); $i++){
            if($i < count($attributes)-1){
                preg_match_all('/(?<=' . $attributes[$i] . ':)[\s\S]*?(?=' . $attributes[$i+1] . ':|Find)/', $sanitize, $matches);
                $matches = array_shift($matches);
                $data[$attributes[$i]] = $matches;
            }
            
        } //var_dump($data); exit;
        return $data;
    }
    
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
            $tweet .= ' http://www.giveshelter.org/component/animaldetail/?id='. $pet['pet_id'];

            App::uses('HttpSocketOauth', 'Vendor');
            $Http = new HttpSocketOauth();

            App::uses('PhpReader', 'Configure');
            Configure::config('default', new PhpReader());
            Configure::load('twitter', 'default');
                      
            $request = array(
                'method' => 'POST',
                'uri' => array(
                  'host' => 'api.twitter.com',
                  'path' => '1/statuses/update.json',
                ),
                'auth' => $this->_get_auth(),
                'body' => array(
                  'status' => $tweet,
                ),
            );
            $response = $Http->request($request);
            
            if($response){
                //$this->out($response);
                $currentDateTime = (string)date('Y-m-d H:i:s');
                $this->Pet->id = $pet['id'];
                $this->Pet->save(array('tweeted_at' => $currentDateTime));
            }
        }
    }
    
    /*
     * Run after update_all_pets to set tweeted_at
     */
    public function set_tweeted_at()
    {
        $request = 'https://api.twitter.com/1/users/show.json?screen_name=dchspets&include_entities=false';
        $results = $this->twitter->get_tweets_array($request);
        $statuses_count = $results['statuses_count'];
        $loop_count = (int)ceil($statuses_count/200); 
        
        $max_id = null;
        $tweets = array();

        for($i=0; $i<$loop_count; $i++){
            $request = 'https://api.twitter.com/1/statuses/user_timeline.json?include_entities=true&include_rts=false&screen_name=dchspets&trim_user=1&count=200';
            if($max_id){
                $request .= '&max_id=' . $max_id;
            }
            $results = $this->twitter->get_tweets_array($request);
            //$this->format_array_dump($results);
            $tweets = array_merge($tweets, $results);
            $last = array_pop($results);
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
    
    protected function _send_email($type, $pets)
    {
        App::uses('CakeEmail', 'Network/Email');
        $email = new CakeEmail();
        $email->config('smtp');
        $email->template('pets', 'default')
                ->emailFormat('html')
                ->from(array('info@jimandkris.com'))
                ->to('info@jimandkris.com')
                ->subject('Tweet Pets DB '. $type)
                ->viewVars(compact('pets', 'type'))
                ->send();
    }
    
    //http://www.danmorgan.net/programming/php-programming/php-strip_tags-fixed-no-more-missing-data/
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
    
    /*
     * Auth for dchspets
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