<?php

class Pet extends AppModel
{
    public function get_pet($pet_id){
        $pet = $this->find('all', array('conditions' => array('pet_id' => $pet_id)));
        if($pet){
            return $pet[0]['Pet'];
        }
        return null;
    }
    
    public function get_pet_ids(){
        $return = array();
        $pet_ids = $this->find('all', array('fields' => array('pet_id')));
        foreach($pet_ids as $id){
            $return[] = $id['Pet']['pet_id'];
        }
        return $return;
    }
    
    public function delete_pet($pet_id){
        $this->deleteAll(array('pet_id' => $pet_id));
    }
}