<?php
/**
 * 検証用のアプリ定義（承認フローつき）。scripts/check_kvgwc.php だけが使う。
 *
 * 承認・部署範囲・数値や選択肢の検証は Core の機能なので、出荷するアプリに
 * 依存させない。ここに固定の定義を置いて、Coreだけで検証が完結するようにする。
 */
return array(
    'key'   => 'testflow',
    'name'  => '検証用（承認あり）',
    'icon'  => '🧪',
    'order' => 900,
    'approval' => true,
    'fields' => array(
        array('key' => 'date',    'label' => '日付',     'type' => 'date', 'required' => true),
        array('key' => 'work',    'label' => '内容',     'type' => 'textarea', 'required' => true, 'rows' => 5),
        array('key' => 'hours',   'label' => '時間',     'type' => 'number', 'min' => 0, 'max' => 24, 'step' => '0.5'),
        array('key' => 'progress', 'label' => '進捗',    'type' => 'radio',
              'options' => array('順調', '遅れ気味', '要相談')),
        array('key' => 'note',    'label' => '所感',     'type' => 'textarea', 'rows' => 4),
    ),
    'permissions' => array(
        'staff' => array('create' => true, 'read' => 'own',  'update' => 'own'),
        'chief' => array('create' => true, 'read' => 'dept', 'update' => 'dept', 'approve' => 'dept'),
        'admin' => array('*' => 'all'),
    ),
    'list_columns' => array('date', 'work', 'progress', '_owner', '_status'),
    'sort_key' => 'date',
);
