<?php
App::uses('HttpSocket', 'Network/Http');

class TwitterUserTimelineSource extends DataSource
{
    public function __construct($config)
    {
        parent::__construct($config);
        $this->Http = new HttpSocket();
    }
    
    public function listSources($data = null)
    {
        return null;
    }
    
    public function read(Model $model, $queryData = array(), $recursive = null)
    {   
        $json = $this->Http->get('https://api.twitter.com/1/statuses/user_timeline.json', $queryData['conditions']);
        $results = json_decode($json, true);  
        if(!$results){
            $error = json_last_error();
            throw new CakeException($error);
        }
        
        //This doesn't work and I don't know why.
        //If I dump out $result here I get one results as expected.
        //But when returned to controller, the result is null.
        if($model->findQueryType === 'first'){
            if(!isset($results[0])){ 
                $results = array();
            }
            else{
                $results = array($results[0]); 
            }
        }
        
        return array($model->alias => $results);
    }
}