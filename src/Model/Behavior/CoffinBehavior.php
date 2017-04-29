<?php

namespace App\Model\Behavior;

use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\I18n\Time;
use Cake\ORM\Behavior;
use ArrayObject;
use Cake\Utility\Inflector;

class CoffinBehavior extends Behavior
{
    private $flag;        // 論理削除フラグ（テーブルカラム名）
    private $type;        // 論理削除フラグのデータ型
    private $options;     // オプションの配列
    private $protections; // 論理削除の対象としないアソシエーションの配列

    const TYPE_TIMESTAMP = 'timestamp';
    const TYPE_BOOLEAN   = 'boolean';

    const FORMAT_DATETIME = 'YYYY/MM/dd HH:mm:ss';

    /**
     * 初期化処理
     *
     * @param array $config
     */
    public function initialize(array $config)
    {
        parent::initialize($config);

        $this->options     = [];
        $this->protections = [];

        if (array_key_exists('flag', $config)) {
            $this->flag = $config['flag'];
        } else {
            $this->flag = 'deleted';
        }

        $typeMap = $this->_table->schema()->typeMap();
        $this->type = $typeMap[$this->flag];

        if (array_key_exists('associations', $config)) {
            $exec = function ($ary) use (&$exec) {
                $ret = [];
                foreach ($ary as $k => $v) {
                    if (is_array($v)) {
                        $ret[$k] = $exec($v);
                        continue;
                    }
                    $ret[$k] = Inflector::camelize($v);
                }
                return $ret;
            };
            $this->options['contain'] = $exec($config['associations']);
        } else {
            $this->options['contain'] = [];
        }

        if (array_key_exists('protections', $config)) {
            foreach ($config['protections'] as $k => $association) {
                $this->protections[] = Inflector::underscore($association);
            }
        } else {
            $this->protections = [];
        }
    }

    /**
     * 永続化データ（persistent）とコンテキスト上のデータ（contextual）を比較し、
     * 論理削除フラグを立てたパッチを生成する（パッチは persistent をベースとする）.
     * 引数として扱えるのは EntityInterface もしくは Array のみ.
     * Visible Property を取得できる場合は引数をオブジェクト（EntityInterface）として扱う.
     *
     * @param $persistent
     * @param $contextual
     */
    private function genPatch($persistent, $contextual)
    {
        if (method_exists($persistent, 'visibleProperties')) {
            $vp = $persistent->visibleProperties();
        }

        // オブジェクト走査
        if (isset($vp)) {
            $ret = $persistent;
            foreach ($vp as $i => $propName) {
                if ($this->is_valid_object($persistent[$propName])) {
                    if (!$contextual->has($propName)) {
                        $this->recursiveDelete($persistent[$propName], $this->is_protected($propName));
                    } else {
                        $ret[$propName] = $this->genPatch($persistent[$propName], $contextual[$propName]);
                    }
                    continue;
                }
                if (is_array($persistent[$propName])) {
                    foreach ($persistent[$propName] as $j => $entity) {
                        $idx = $this->is_same_id_exist($entity->id, $contextual[$propName]);
                        if ($idx === false) {
                            $this->recursiveDelete($persistent[$propName][$j], $this->is_protected($propName));
                        } else {
                            $ret[$propName][$j] = $this->genPatch($persistent[$propName][$j], $contextual[$propName][$idx]);
                        }
                    }
                }
            }
            return $ret;
        }

        // 配列走査
        $ret = [];
        foreach ($persistent as $i => $entity) {
            $ret[] = $this->genPatch($persistent[$i], $contextual[$i]);
        }
        return $ret;
    }

    /**
     * 再帰的に論理削除フラグを設定する.
     *
     * @param $persistent
     * @param $isProtected
     * @return array
     */
    private function recursiveDelete($persistent, $isProtected)
    {
        if (method_exists($persistent, 'visibleProperties')) {
            $vp = $persistent->visibleProperties();
        }

        // オブジェクト走査
        if (isset($vp)) {
            foreach ($vp as $i => $propName) {
                $property = $persistent[$propName];
                if ($this->is_valid_object($property) || is_array($property)) {
                    $persistent[$propName] = $this->recursiveDelete($property, $this->is_protected($propName));
                    continue;
                }
                // 論理削除
                if ($propName === $this->flag && !$isProtected) {
                    if ($this->type === self::TYPE_TIMESTAMP) {
                        $persistent[$propName] = Time::now()->i18nFormat(self::FORMAT_DATETIME);
                        continue;
                    }
                    if ($this->type === self::TYPE_BOOLEAN) {
                        $persistent[$propName] = true;
                        continue;
                    }
                }
            }
            return $persistent;
        }

        // 配列走査
        foreach ($persistent as $i => $entity) {
            $this->recursiveDelete($persistent[$i], $isProtected);
        }
        return $persistent;
    }

    /**
     * contextual に対して再帰的にパッチ処理を行う.
     *
     * @param $contextual
     * @param $patch
     */
    private function patch(&$contextual, $patch)
    {
        if (method_exists($patch, 'visibleProperties')) {
            $vp = $patch->visibleProperties();
        }

        // オブジェクト走査
        if (isset($vp)) {
            foreach ($vp as $i => $propName) {
                if ($this->is_valid_object($patch[$propName])) {
                    if (!$contextual->has($propName)) {
                        $contextual[$propName] = $patch[$propName];
                    } else {
                        $this->patch($contextual[$propName], $patch[$propName]);
                    }
                    continue;
                }
                if (is_array($patch[$propName]) && count($patch[$propName])) {
                    foreach ($patch[$propName] as $j => $entity) {
                        $idx = $this->is_same_id_exist($entity->id, $contextual[$propName]);
                        if ($idx === false) {
                            $contextual[$propName][] = $patch[$propName][$j];
                        } else {
                            $this->patch($contextual[$propName][$j], $patch[$propName][$j]);
                        }
                    }
                }
            }
            return;
        }

        // 配列走査
        foreach ($patch as $i => $entity) {
            $this->patch($contextual[$i], $patch[$i]);
        }
    }

    /**
     * エンティティの配列から同一の id を持つものを探す.
     * id が一致すれば配列のインデックスを返し、一致するものがなければ false を返す.
     *
     * @param $id
     * @param $entities
     * @return bool|int|string
     */
    private function is_same_id_exist($id, $entities)
    {
        if (empty($entities)) {
            return false;
        }

        foreach ($entities as $idx => $entity) {
            if (is_object($entity) && $id === $entity->id) {
                return $idx;
            }
        }

        return false;
    }

    /**
     * Ignore time objects.
     *
     * @param $obj
     * @return bool
     */
    private function is_valid_object($obj)
    {
        if (!is_object($obj)) return false;

        if ($obj instanceof \Cake\I18n\Date)       return false;
        if ($obj instanceof \Cake\I18n\Time)       return false;
        if ($obj instanceof \Cake\I18n\FrozenDate) return false;
        if ($obj instanceof \Cake\I18n\FrozenTime) return false;

        return true;
    }

    private function is_protected($propName)
    {
        return in_array($propName, $this->protections) ? true : false;
    }

    /**
     * コールバックイベント
     * https://book.cakephp.org/3.0/ja/orm/table-objects.html#beforesave
     *
     * @param Event $event
     * @param EntityInterface $entity
     * @param ArrayObject $options
     */
    public function beforeSave(Event $event, EntityInterface $entity, ArrayObject $options)
    {
        if ($entity->isNew()) {
            return;
        }

        $persistent = $this->_table->get($entity->id, ['contain' => $this->options['contain']]);

        $patch = $this->genPatch($persistent, $entity);

        $this->patch($entity, $patch);
    }
}
