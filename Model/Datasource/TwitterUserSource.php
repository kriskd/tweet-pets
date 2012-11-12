<?php
App::uses('HttpSocket', 'Network/Http');

class TwitterUserSource extends DataSource
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
        $json = $this->Http->get('https://api.twitter.com/1/users/show.json', $queryData['conditions']);
        $results = json_decode($json, true); 
        if(!$results){
            $error = json_last_error();
            throw new CakeException($error);
        } 
        return array($model->alias => $results);
    }
}