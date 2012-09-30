<?php
class TempShell extends AppShell
{
    protected $HttpSoocket;
    
    public function __construct()
    {
        parent::__construct();
        App::uses('HttpSocket', 'Network/Http');
        $this->HttpSocket = new HttpSocket();
    }
    
    public function main()
    {
        $request = 'https://api.twitter.com/1/users/show.json?screen_name=kriskkd&include_entities=false';
        $json = $this->HttpSocket->get($request);
        $results = json_decode($json, true);
        $statuses_count = $results['statuses_count'];
        $loop_count = (int)ceil($statuses_count/200); 
        
        $max_id = null;
        $tweets = array();

        for($i=0; $i<$loop_count; $i++){
            $request = 'https://api.twitter.com/1/statuses/user_timeline.json?include_entities=true&include_rts=false&screen_name=kriskkd&trim_user=1&count=200';
            if($max_id){
                $request .= '&max_id=' . $max_id;
            }
            $json = $this->HttpSocket->get($request);
            $results = json_decode($json, true);
            //$this->format_array_dump($results);
            $tweets = array_merge($tweets, $results);
            $last = array_pop($results);
            $last_id = $last['id'];
            $max_id = $last_id-1;
        }
        foreach($tweets as $tweet){
            if(stristr($tweet['text'], 'node.js')){
                var_dump($tweet);
            }
        }
    }
}