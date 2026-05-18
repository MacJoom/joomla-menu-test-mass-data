<?php
/**
 * Joomla 6 menu stress data generator.
 *
 * Run from Joomla root:
 * php cli/generate-menu-stress-data.php
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Version;

const _JEXEC = 1;

require_once dirname(__DIR__) . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

$db  = Factory::getContainer()->get('DatabaseDriver');
$now = gmdate('Y-m-d H:i:s');

$menuCount      = 10;
$menuItemsTotal = 1500;
$articlesTotal  = 1500;
$maxDepth       = 8;

echo "Joomla " . (new Version())->getShortVersion() . PHP_EOL;
echo "Generating {$menuCount} menus, {$menuItemsTotal} menu items, {$articlesTotal} articles..." . PHP_EOL;

function tableColumns(string $table): array
{
    global $db;

    return array_keys($db->getTableColumns($table, false));
}

function insertRow(string $table, array $data): int
{
    global $db;

    $columns = tableColumns($table);
    $data    = array_intersect_key($data, array_flip($columns));

    $object = (object) $data;

    $db->insertObject($table, $object);

    return (int) $db->insertid();
}

function deleteStressData(): void
{
    global $db;

    echo "Removing previous stress test data..." . PHP_EOL;

    $db->setQuery(
        $db->getQuery(true)
            ->delete($db->quoteName('#__menu'))
            ->where($db->quoteName('menutype') . ' LIKE ' . $db->quote('stressmenu%'))
    )->execute();

    $db->setQuery(
        $db->getQuery(true)
            ->delete($db->quoteName('#__menu_types'))
            ->where($db->quoteName('menutype') . ' LIKE ' . $db->quote('stressmenu%'))
    )->execute();

    $db->setQuery(
        $db->getQuery(true)
            ->delete($db->quoteName('#__content'))
            ->where($db->quoteName('alias') . ' LIKE ' . $db->quote('stress-article-%'))
    )->execute();

    $db->setQuery(
        $db->getQuery(true)
            ->delete($db->quoteName('#__categories'))
            ->where($db->quoteName('alias') . ' = ' . $db->quote('stress-test-category'))
            ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
    )->execute();
}

function getComponentId(string $element): int
{
    global $db;

    $db->setQuery(
        $db->getQuery(true)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote($element))
    );

    return (int) $db->loadResult();
}

deleteStressData();

$comContentId = getComponentId('com_content');

if (!$comContentId) {
    throw new RuntimeException('Could not find com_content extension_id.');
}

$categoryId = insertRow('#__categories', [
    'asset_id'      => 0,
    'parent_id'     => 1,
    'lft'           => 0,
    'rgt'           => 0,
    'level'         => 1,
    'path'          => 'stress-test-category',
    'extension'     => 'com_content',
    'title'         => 'Stress Test Category',
    'alias'         => 'stress-test-category',
    'description'   => '',
    'published'     => 1,
    'access'        => 1,
    'params'        => '{}',
    'metadata'      => '{}',
    'language'      => '*',
    'created_time'  => $now,
    'modified_time' => $now,
]);

echo "Created category ID {$categoryId}" . PHP_EOL;

$articleIds = [];

for ($i = 1; $i <= $articlesTotal; $i++) {
    $articleIds[] = insertRow('#__content', [
        'asset_id'    => 0,
        'title'       => 'Stress Article ' . $i,
        'alias'       => 'stress-article-' . $i,
        'introtext'   => '<p>This is generated stress test article ' . $i . '.</p>',
        'fulltext'    => '<p>Generated content for Joomla menu stress testing.</p>',
        'state'       => 1,
        'catid'       => $categoryId,
        'created'     => $now,
        'created_by'  => 1,
        'modified'    => $now,
        'modified_by' => 1,
        'publish_up'  => $now,
        'access'      => 1,
        'language'    => '*',
        'attribs'     => '{}',
        'metadata'    => '{}',
        'metakey'     => '',
        'metadesc'    => '',
        'images'      => '{}',
        'urls'        => '{}',
        'version'     => 1,
    ]);
}

echo "Created {$articlesTotal} articles" . PHP_EOL;

$menuTypes = [];

for ($m = 1; $m <= $menuCount; $m++) {
    $menutype = 'stressmenu' . $m;

    insertRow('#__menu_types', [
        'menutype'    => $menutype,
        'title'       => 'Stress Menu ' . $m,
        'description' => 'Generated menu for drag/drop ordering stress tests',
        'client_id'   => 0,
    ]);

    $menuTypes[] = $menutype;
}

echo "Created {$menuCount} menu types" . PHP_EOL;

$itemsPerMenu = intdiv($menuItemsTotal, $menuCount);
$globalIndex  = 1;

foreach ($menuTypes as $menutype) {
    echo "Building {$menutype}..." . PHP_EOL;

    $nodes = [];

    for ($i = 1; $i <= $itemsPerMenu; $i++) {
        if ($i === 1) {
            $parentTempId = 0;
            $level        = 1;
            $path         = 'stress-item-' . $globalIndex;
        } else {
            $possibleParents = array_filter(
                $nodes,
                static fn ($node) => $node['level'] < $maxDepth
            );

            $parent = $possibleParents[array_rand($possibleParents)];

            $parentTempId = $parent['temp_id'];
            $level        = $parent['level'] + 1;
            $path         = $parent['path'] . '/stress-item-' . $globalIndex;
        }

        $nodes[] = [
            'temp_id'        => $globalIndex,
            'parent_temp_id' => $parentTempId,
            'level'          => $level,
            'path'           => $path,
            'article_id'     => $articleIds[($globalIndex - 1) % count($articleIds)],
            'title'          => 'Stress Item ' . $globalIndex,
            'alias'          => 'stress-item-' . $globalIndex,
        ];

        $globalIndex++;
    }

    $children = [];

    foreach ($nodes as $node) {
        $children[$node['parent_temp_id']][] = $node['temp_id'];
    }

    $counter = 1;
    $bounds  = [];

    $walk = function (int $tempId) use (&$walk, &$counter, &$bounds, &$children): void {
        $left = $counter++;

        foreach ($children[$tempId] ?? [] as $childId) {
            $walk($childId);
        }

        $right = $counter++;

        if ($tempId !== 0) {
            $bounds[$tempId] = [$left, $right];
        }
    };

    $walk(0);

    $realIds = [];

    foreach ($nodes as $node) {
        [$lft, $rgt] = $bounds[$node['temp_id']];

        $parentId = 1;

        if ($node['parent_temp_id'] !== 0 && isset($realIds[$node['parent_temp_id']])) {
            $parentId = $realIds[$node['parent_temp_id']];
        }

        $realIds[$node['temp_id']] = insertRow('#__menu', [
            'menutype'          => $menutype,
            'title'             => $node['title'],
            'alias'             => $node['alias'],
            'note'              => '',
            'path'              => $node['path'],
            'link'              => 'index.php?option=com_content&view=article&id=' . $node['article_id'],
            'type'              => 'component',
            'published'         => 1,
            'parent_id'         => $parentId,
            'level'             => $node['level'],
            'component_id'      => $comContentId,
            'checked_out'       => 0,
            'checked_out_time'  => null,
            'browserNav'        => 0,
            'access'            => 1,
            'img'               => '',
            'template_style_id' => 0,
            'params'            => '{}',
            'lft'               => $lft,
            'rgt'               => $rgt,
            'home'              => 0,
            'language'          => '*',
            'client_id'         => 0,
            'publish_up'        => null,
            'publish_down'      => null,
        ]);
    }

    echo "Inserted {$itemsPerMenu} items into {$menutype}" . PHP_EOL;
}

echo PHP_EOL;
echo "Done." . PHP_EOL;
echo "Now test:" . PHP_EOL;
echo "1. Administrator → Menus → Manage → Menu Items" . PHP_EOL;
echo "2. Filter by one menu, e.g. Stress Menu 1" . PHP_EOL;
echo "3. Set Max Levels = 2" . PHP_EOL;
echo "4. Drag one item up/down" . PHP_EOL;
echo "5. Immediately unpublish the newly-adjacent sibling" . PHP_EOL;
