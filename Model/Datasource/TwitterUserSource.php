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
        App::uses('HttpSocketOauth', 'Vendor');
        $Http = new HttpSocketOauth();
                  
        $request = array(
            'method' => 'GET',
            'uri' => array(
                'scheme' => 'https',
                'host' => 'api.twitter.com',
                'path' => '1.1/users/show.json',
                'query' => $queryData['conditions']
            ),
            'auth' => $this->_get_auth(),
        );
        
        $response = $Http->request($request);
        $results = json_decode($response->body, true);
        
        if(!$results){
            $error = json_last_error();
            throw new CakeException($error);
        } 
        return array($model->alias => $results);
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