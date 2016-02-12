<?php

/**
 * RelationTrait
 *
 * @author Yohanes Candrajaya <moo.tensai@gmail.com>
 * @since 1.0
 */

namespace mootensai\relation;

use yii\db\ActiveQuery;
use \yii\db\ActiveRecord;
use \yii\db\Exception;
use \yii\helpers\Inflector;
use \yii\helpers\StringHelper;

trait RelationTrait
{

    public function loadAll($POST)
    {
        if ($this->load($POST)) {
            $shortName = StringHelper::basename(get_class($this));
            foreach ($POST as $key => $value) {
                if ($key != $shortName && strpos($key, '_') === false) {
                    /* @var $rel ActiveQuery */
                    /* @var $this ActiveRecord */
                    /* @var $relObj ActiveRecord */
                    $isHasMany = is_array($value);
                    $relName = ($isHasMany) ? lcfirst(Inflector::pluralize($key)) : lcfirst($key);
                    $rel = $this->getRelation($relName);
                    $relModelClass = $rel->modelClass;
                    $relPKAttr = $relModelClass::primaryKey();
                    $isManyMany = count($relPKAttr) > 1;
                    if ($isManyMany) {
                        foreach ($value as $relPost) {
                            if (!empty(array_filter($relPost))) {
                                $condition = [];
                                $condition[$this->primaryKey()[0]] = $this->primaryKey;
                                foreach ($relPost as $relAttr => $relAttrVal) {
                                    if (in_array($relAttr, $relPKAttr))
                                        $condition[$relAttr] = $relAttrVal;
                                }
                                $relObj = $relModelClass::findOne($condition);
                                if (is_null($relObj)) {
                                    $relObj = new $relModelClass;
                                }
                                $relObj->load($relPost, '');
                                $container[] = $relObj;
                            }
                        }
                        $this->populateRelation($relName, $container);
                    } else if ($isHasMany) {
                        $container = [];
                        foreach ($value as $relPost) {
                            if (!empty(array_filter($relPost))) {
                                /* @var $relObj ActiveRecord */
                                $relObj = (empty($relPost[$relPKAttr[0]])) ? new $relModelClass : $relModelClass::findOne($relPost[$relPKAttr[0]]);
                                $relObj->load($relPost, '');
                                $container[] = $relObj;
                            }
                        }
                        $this->populateRelation($relName, $container);
                    } else {
                        $relObj = (empty($relPost[$relPKAttr[0]])) ? new $relModelClass : $relModelClass::findOne($relPost[$relPKAttr[0]]);
                        $relObj->load($value);
                        $this->populateRelation($relName, $relObj);
                    }
                }
            }
            return true;
        } else {
            return false;
        }
    }

    public function saveAll()
    {
        /* @var $this ActiveRecord */
        $db = $this->getDb();
        $trans = $db->beginTransaction();
        try {
            if ($this->save()) {
                $error = 0;
                if (!empty($this->relatedRecords)) {
                    foreach ($this->relatedRecords as $name => $records) {
                        if (!empty($records)) {
                            $isHasMany = is_array($records);
                            $AQ = $this->getRelation($name);
                            $link = $AQ->link;
                            $notDeletedPK = [];
                            $relPKAttr = $records[0]->primaryKey();
                            $isManyMany = (count($relPKAttr) > 1);
                            if ($isHasMany) {
                                /* @var $relModel ActiveRecord */
                                $i = 0;
                                foreach ($records as $index => $relModel) {
                                    $notDeletedFK = [];
                                    foreach ($link as $key => $value) {
                                        $relModel->$key = $this->$value;
                                        if ($isManyMany) $notDeletedFK[$key] = $this->$value;
                                        elseif ($isHasMany) $notDeletedFK[$key] = "$key = '{$this->$value}'";
                                    }
                                    $relSave = $relModel->save();

                                    if (!$relSave || !empty($relModel->errors)) {
                                        $relModelWords = Inflector::camel2words(StringHelper::basename($AQ->modelClass));
                                        $index++;
                                        foreach ($relModel->errors as $validation) {
                                            foreach ($validation as $errorMsg) {
                                                $this->addError($name, "$relModelWords #$index : $errorMsg");
                                            }
                                        }
                                        $error = 1;
                                    } else {
                                        //GET PK OF REL MODEL
                                        if ($isManyMany) {
                                            foreach ($relModel->primaryKey as $attr => $value) {
                                                $notDeletedPK[$i][$attr] = "'$value'";
                                                $fields[$attr] = "";
                                            }
                                        } else {
                                            $notDeletedPK[] = "'$relModel->primaryKey'";
                                        }
                                    }
                                    $i++;
                                }
                                if (!$this->isNewRecord) {
                                    //DELETE WITH 'NOT IN' PK MODEL & REL MODEL
                                    if ($isManyMany) {
//                                        echo "composite pk\n";
//                                        echo "not deleted PK : \n";
//                                        print_r($notDeletedPK);
//                                        echo "not deleted FK : \n";
//                                        print_r($notDeletedFK);
//                                        echo "\nfields : \n";
//                                        print_r($fields) . "\n";
//                                        $contoh = ['and',['actor_id' => 1],['not in', new \yii\db\Expression('(actor_id, film_id)'),
//                                        [
//                                            new \yii\db\Expression('(1,1)'),
//                                            new \yii\db\Expression('(1,23)'),
//                                            new \yii\db\Expression('(1,25)'),
//                                            new \yii\db\Expression('(1,980)'),
//                                            new \yii\db\Expression('(1,970)'),
//                                            new \yii\db\Expression('(1,939)')
//                                        ]]];
//                                        echo $relModel::find()->where(['and',['actor_id' => 1],['not in', new \yii\db\Expression('(actor_id, film_id)'),
//                                            [
//                                                new \yii\db\Expression('(1,1)'),
//                                                new \yii\db\Expression('(1,23)'),
//                                                new \yii\db\Expression('(1,25)'),
//                                                new \yii\db\Expression('(1,980)'),
//                                                new \yii\db\Expression('(1,970)'),
//                                                new \yii\db\Expression('(1,939)')
//                                            ]]])->createCommand()->rawSql;
                                        $compiledFields = implode(", ", array_keys($fields));
                                        $compiledNotDeletedPK = ['and', $notDeletedFK];
                                        $notIn = ['not in', new \yii\db\Expression("($compiledFields)")];
                                        foreach ($notDeletedPK as $value) {
                                            $v = [];
                                            foreach ($fields as $key => $f) {
                                                $v[] = $value[$key];
                                            }
                                            $c = implode(',', $v);
                                            $content[] = new \yii\db\Expression("($c)");
                                        }
                                        array_push($notIn, $content);
                                        array_push($compiledNotDeletedPK, $notIn);
//                                        $a = $relModel->findAll($compiledNotDeletedPK);
//                                        echo "Compiled Not Deleted PK :\n ";
//                                        print_r($compiledNotDeletedPK). "\n";
//                                        print_r($a);
//                                        foreach($a as $ac){
//                                            print_r($ac->attributes);
//                                        }
//                                        print_r($compiledNotDeletedPK) . "\n";
//                                        echo "Contoh :\n ";
//                                        print_r($contoh);
//                                        echo $relModel->find()->where($compiledNotDeletedPK)->createCommand()->rawSql;
                                        $relModel->deleteAll($compiledNotDeletedPK);
                                        try {
                                            $relModel->deleteAll($compiledNotDeletedPK);
                                        } catch (\yii\db\IntegrityException $exc) {
                                            $this->addError($name, "Data can't be deleted because it's still used by another data.");
                                        }
                                    } else {
                                        $notDeletedFK = implode(' AND ', $notDeletedFK);
                                        $compiledNotDeletedPK = implode(',', $notDeletedPK);
                                        if (!empty($compiledNotDeletedPK)) {
                                            try {
                                                $relModel->deleteAll($notDeletedFK . ' AND ' . $relPKAttr[0] . " NOT IN ($compiledNotDeletedPK)");

                                            } catch (\yii\db\IntegrityException $exc) {
                                                $this->addError($name, "Data can't be deleted because it's still used by another data.");
                                            }
                                        }
                                    }
                                }
                            } else {
                                //Has One
                            }

                        }
                    }
                } else {
                    //No Children left
                    echo "No Children left";
                    if (!$this->isNewRecord) {
                        $relData = $this->getRelationData();
                        foreach ($relData as $rel) {
                            /* @var $relModel ActiveRecord */
                            if (empty($rel['via'])) {
                                $relModel = new $rel['modelClass'];
                                $condition = [];
                                $isManyMany = count($relModel->primaryKey()) > 1;
                                if ($isManyMany) {
                                    foreach ($rel['link'] as $k => $v) {
                                        $condition[] = $k . " = " . $this->$v;
                                    }
                                    try {
                                        $relModel->deleteAll(implode(" AND ", $condition));
                                    } catch (\yii\db\IntegrityException $exc) {
                                        $this->addError($rel['name'], "Data can't be deleted because it's still used by another data.");
                                        $error = 1;
                                    }
                                } else {
                                    foreach ($rel['link'] as $k => $v) {
                                        $condition[] = $k . " = " . $this->$v;
                                    }
                                    try {
                                        $relModel->deleteAll(implode(" AND ", $condition));
                                    } catch (\yii\db\IntegrityException $exc) {
                                        $this->addError($rel['name'], "Data can't be deleted because it's still used by another data.");
                                        $error = 1;
                                    }
                                }
                            }
                        }
                    }
                }

                if ($error) {
                    $trans->rollback();
                    return false;
                }
                $trans->commit();
                return true;
            } else {
                return false;
            }
        } catch (Exception $exc) {
            $trans->rollBack();
            throw $exc;
        }
    }

    public function deleteWithRelated()
    {
        /* @var $this ActiveRecord */
        $db = $this->getDb();
        $trans = $db->beginTransaction();
        try {
            $error = 0;
            $relData = $this->getRelationData();
            foreach ($relData as $data) {
                if ($data['ismultiple']) {
                    $link = $data['link'];
                    if (count($this->{$data['name']})) {
                        $relPKAttr = $this->{$data['name']}[0]->primaryKey();
                        $isCompositePK = (count($relPKAttr) > 1);
                        foreach ($link as $key => $value) {
                            if (isset($this->$value)) {
                                $array[$key] = $key . ' = ' . $this->$value;
                            }
                        }
                        $error = !$this->{$data['name']}[0]->deleteAll(implode(' AND ', $array));
                    }
                }
            }
            if ($error) {
                $trans->rollback();
                return false;
            }
            if ($this->delete()) {
                $trans->commit();
                return true;
            }
            $trans->rollBack();
        } catch (Exception $exc) {
            $trans->rollBack();
            throw $exc;
        }
    }


    public function getRelationData()
    {
        $ARMethods = get_class_methods('\yii\db\ActiveRecord');
        $modelMethods = get_class_methods('\yii\base\Model');
        $reflection = new \ReflectionClass($this);
        $i = 0;
        $stack = [];
        /* @var $method \ReflectionMethod */
        foreach ($reflection->getMethods() as $method) {
            if (in_array($method->name, $ARMethods) || in_array($method->name, $modelMethods)) {
                continue;
            }
            if ($method->name === 'bindModels') {
                continue;
            }
            if ($method->name === 'attachBehaviorInternal') {
                continue;
            }
            if ($method->name === 'loadAll') {
                continue;
            }
            if ($method->name === 'saveAll') {
                continue;
            }
            if ($method->name === 'getRelationData') {
                continue;
            }
            if ($method->name === 'getAttributesWithRelatedAsPost') {
                continue;
            }
            if ($method->name === 'getAttributesWithRelated') {
                continue;
            }
            if ($method->name === 'deleteWithRelated') {
                continue;
            }
            try {
                $rel = call_user_func(array($this, $method->name));
                if ($rel instanceof \yii\db\ActiveQuery) {
                    $stack[$i]['name'] = lcfirst(str_replace('get', '', $method->name));
                    $stack[$i]['method'] = $method->name;
                    $stack[$i]['ismultiple'] = $rel->multiple;
                    $stack[$i]['modelClass'] = $rel->modelClass;
                    $stack[$i]['link'] = $rel->link;
                    $stack[$i]['via'] = $rel->via;
                    $i++;
                }
            } catch (\yii\base\ErrorException $exc) {
            }
        }
        return $stack;
    }

    /* this function is deprecated */

    public function getAttributesWithRelatedAsPost()
    {
        $return = [];
        $shortName = StringHelper::basename(get_class($this));
        $return[$shortName] = $this->attributes;
        foreach ($this->relatedRecords as $records) {
            foreach ($records as $index => $record) {
                $shortNameRel = StringHelper::basename(get_class($record));
                $return[$shortNameRel][$index] = $record->attributes;
            }
        }
        return $return;
    }

    public function getAttributesWithRelated()
    {
        $return = $this->attributes;
        foreach ($this->relatedRecords as $name => $records) {
            foreach ($records as $index => $record) {
                $return[$name][$index] = $record->attributes;
            }
        }
        return $return;
    }

}
