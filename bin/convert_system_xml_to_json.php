#!/usr/bin/env php
<?php
/**
 * Converts system.xml to dynamic_settings.json
 * This script parses the Magento system.xml configuration and creates a JSON file
 * that can be used for dynamic settings rendering.
 * 
 * Usage: php bin/convert_system_xml_to_json.php
 */

$xmlFile = __DIR__ . '/../etc/adminhtml/system.xml';
$jsonFile = __DIR__ . '/../etc/dynamic_settings.json';

if (!file_exists($xmlFile)) {
    echo "Error: system.xml not found at $xmlFile\n";
    exit(1);
}

$xml = simplexml_load_file($xmlFile);

$sections = [];

foreach ($xml->system->section as $section) {
    $sectionId = (string)$section['id'];
    
    // Skip non-myparcelnl sections (like sales_email and carriers)
    if (strpos($sectionId, 'myparcelnl_magento') !== 0) {
        continue;
    }
    
    $sectionData = [
        'id' => $sectionId,
        'label' => (string)$section->label,
        'sort_order' => (int)$section['sortOrder'],
        'showInDefault' => ((string)$section['showInDefault']) === '1',
        'showInWebsite' => ((string)$section['showInWebsite']) === '1',
        'showInStore' => ((string)$section['showInStore']) === '1',
        'groups' => []
    ];
    
    foreach ($section->group as $group) {
        $groupId = (string)$group['id'];
        
        $groupData = [
            'id' => $groupId,
            'label' => isset($group->label) ? (string)$group->label : '',
            'comment' => isset($group->comment) ? trim((string)$group->comment) : null,
            'sort_order' => (int)$group['sortOrder'],
            'showInDefault' => ((string)$group['showInDefault']) === '1',
            'showInWebsite' => ((string)$group['showInWebsite']) === '1',
            'showInStore' => ((string)$group['showInStore']) === '1',
            'fields' => []
        ];
        
        // Check if group has a frontend_model (like SupportTab)
        if (isset($group->frontend_model)) {
            $groupData['frontend_model'] = (string)$group->frontend_model;
        }
        
        foreach ($group->field as $field) {
            $fieldId = (string)$field['id'];
            $fieldType = (string)$field['type'];
            
            // Build the config path
            $path = $sectionId . '/' . $groupId . '/' . $fieldId;
            
            $fieldData = [
                'id' => $fieldId,
                'path' => $path,
                'type' => $fieldType,
                'label' => isset($field->label) ? (string)$field->label : '',
                'sort_order' => (int)$field['sortOrder'],
                'showInDefault' => ((string)$field['showInDefault']) === '1',
                'showInWebsite' => ((string)$field['showInWebsite']) === '1',
                'showInStore' => ((string)$field['showInStore']) === '1'
            ];
            
            // Optional attributes
            if (isset($field->tooltip)) {
                $fieldData['tooltip'] = trim((string)$field->tooltip);
            }
            
            if (isset($field->comment)) {
                $fieldData['comment'] = trim((string)$field->comment);
            }
            
            if (isset($field->source_model)) {
                $fieldData['source_model'] = (string)$field->source_model;
            }
            
            if (isset($field->frontend_model)) {
                $fieldData['frontend_model'] = (string)$field->frontend_model;
            }
            
            if (isset($field->validate)) {
                $fieldData['validate'] = (string)$field->validate;
            }
            
            if (isset($field->frontend_class)) {
                $fieldData['frontend_class'] = (string)$field->frontend_class;
            }
            
            if (isset($field->can_be_empty)) {
                $fieldData['can_be_empty'] = ((string)$field->can_be_empty) === '1';
            }
            
            if (isset($field['canRestore'])) {
                $fieldData['canRestore'] = ((string)$field['canRestore']) === '1';
            }
            
            // Handle depends
            if (isset($field->depends)) {
                $depends = [];
                foreach ($field->depends->field as $depField) {
                    $depFieldId = (string)$depField['id'];
                    $depValue = (string)$depField;
                    
                    // Build the full path for the dependency
                    // Dependencies in system.xml are relative to the current group
                    $depPath = $sectionId . '/' . $groupId . '/' . $depFieldId;
                    
                    $depends[] = [
                        'field' => $depPath,
                        'value' => $depValue
                    ];
                }
                if (!empty($depends)) {
                    $fieldData['depends'] = $depends;
                }
            }
            
            $groupData['fields'][] = $fieldData;
        }
        
        // Only add group if it has fields or a frontend_model
        if (!empty($groupData['fields']) || isset($groupData['frontend_model'])) {
            $sectionData['groups'][] = $groupData;
        }
    }
    
    if (!empty($sectionData['groups'])) {
        $sections[] = $sectionData;
    }
}

$output = [
    '_comment' => 'This file is auto-generated from system.xml. It can be modified or replaced with API responses.',
    '_generated' => date('Y-m-d H:i:s'),
    'sections' => $sections
];

$json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (file_put_contents($jsonFile, $json) === false) {
    echo "Error: Could not write to $jsonFile\n";
    exit(1);
}

echo "Successfully generated $jsonFile\n";
echo "Total sections: " . count($sections) . "\n";

$totalGroups = 0;
$totalFields = 0;
foreach ($sections as $section) {
    $totalGroups += count($section['groups']);
    foreach ($section['groups'] as $group) {
        $totalFields += count($group['fields']);
    }
}
echo "Total groups: $totalGroups\n";
echo "Total fields: $totalFields\n";
