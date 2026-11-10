<?php
/**
 * 共有スケジュール — 同梱アプリその1。
 *
 * 全員が全員の予定を見られる('read' => 'all')のが要点。ここを 'own' にすると
 * ただの個人手帳になり、グループウェアではなくなる。編集は自分の予定だけ。
 */
return array(
    'key'   => 'schedule',
    'name'  => '共有スケジュール',
    'icon'  => '📅',
    'order' => 10,
    'fields' => array(
        array('key' => 'date',  'label' => '日付',   'type' => 'date',   'required' => true),
        array('key' => 'start', 'label' => '開始',   'type' => 'time'),
        array('key' => 'end',   'label' => '終了',   'type' => 'time'),
        array('key' => 'title', 'label' => '件名',   'type' => 'text',   'required' => true,
              'maxlength' => 100, 'placeholder' => '例) A社 定例打合せ'),
        array('key' => 'kind',  'label' => '種別',   'type' => 'select',
              'options' => array('打合せ', '来客', '外出', '出張', '休暇', 'その他')),
        array('key' => 'place', 'label' => '場所',   'type' => 'text', 'maxlength' => 100),
        array('key' => 'memo',  'label' => 'メモ',   'type' => 'textarea', 'rows' => 3),
    ),
    'permissions' => array(
        // 予定は全員に見えてこそ意味がある。編集・削除は自分のものだけ。
        'staff' => array('create' => true, 'read' => 'all', 'update' => 'own',  'delete' => 'own'),
        'chief' => array('create' => true, 'read' => 'all', 'update' => 'dept', 'delete' => 'dept'),
        'admin' => array('*' => 'all'),
    ),
    // スマホの標準カレンダーで見えるようにする。どの項目が日時・件名・場所かを教える
    'caldav' => array(
        'date' => 'date', 'start' => 'start', 'end' => 'end',
        'title' => 'title', 'location' => 'place', 'note' => 'memo',
    ),
    'list_columns' => array('date', 'start', 'title', 'kind', 'place', '_owner'),
    'sort_key' => 'date',
    'sort_asc' => true,
);
