<?php
/**
 * Abstract class that define a type mapping of EAV entities.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Smile Searchandising Suite to newer
 * versions in the future.
 *
 * This work is a fork of Johann Reinke <johann@bubblecode.net> previous module
 * available at https://github.com/jreinke/magento-elasticsearch
 *
 * @category  Smile
 * @package   Smile_ElasticSearch
 * @author    Aurelien FOUCRET <aurelien.foucret@smile.fr>
 * @copyright 2013 Smile
 * @license   Apache License Version 2.0
 */
abstract class Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Mapping_Catalog_Eav_Abstract
    extends Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Mapping_Abstract
{
    /**
     * @var Mage_Eav_Model_Resource_Attribute_Collection
     */
    protected $_attributeCollectionModel;

    /**
     * @var array
     */
    protected $_mapping                  = null;

    /**
     * @var array
     */
    protected $_authorizedBackendModels  = array();

    /**
     * @var array
     */
    protected $_suggestInputAttributes   = array('name');

    /**
     * @var array
     */
    protected $_suggestPayloadAttributes = array('entity_id');

    /**
     * Get mapping properties as stored into the index
     *
     * @param string $useCache Indicates if the cache should be used or if the mapping should be rebuilt.
     *
     * @return array
     */
    public function getMappingProperties($useCache = true)
    {
        $cacheKey = 'SEARCH_ENGINE_MAPPING_' . $this->_type;

        if ($this->_mapping == null && $useCache) {
            $mapping = Mage::app()->loadCache($cacheKey);
            if ($mapping) {
                $this->_mapping = unserialize($mapping);
            }
        }

        if ($this->_mapping === null) {

            $this->_mapping = array('properties' => array());

            $entityType = Mage::getModel('eav/entity_type')->loadByCode($this->_entityType);

            $attributes = Mage::getResourceModel($this->_attributeCollectionModel)
                ->setEntityTypeFilter($entityType->getEntityTypeId());

            foreach ($attributes as $attribute) {
                $this->_mapping['properties'] = array_merge($this->_mapping['properties'], $this->_getAttributeMapping($attribute));
            }

            $this->_mapping['properties']['unique']   = array('type' => 'string');
            $this->_mapping['properties']['id']       = array('type' => 'long');
            $this->_mapping['properties']['store_id'] = array('type' => 'integer');

            foreach (Mage::app()->getStores() as $store) {
                $languageCode = Mage::helper('smile_elasticsearch')->getLanguageCodeByStore($store);
                $this->_mapping['properties'][Mage::helper('smile_elasticsearch')->getSuggestFieldName($store)] = array(
                    'type' => 'completion',
                    'payloads' => true,
                    'index_analyzer'  => 'analyzer_' . $languageCode,
                    'search_analyzer' => 'analyzer_' . $languageCode,
                    'preserve_separators' => false,
                    'preserve_position_increments' => false,
                    'context' => array(
                        'store_id'   => array('type' => 'category', 'default' => '0'),
                        'type'       => array('type' => 'category', 'default' => $this->_type),
                        'visibility' => array('type' => 'category', 'default' => 1),
                        'status'     => array('type' => 'category', 'default' => 1)
                    )
                );
            }

            $mapping = serialize($this->_mapping);

            Mage::app()->saveCache(
                $mapping,
                $cacheKey,
                array('CONFIG', 'EAV_ATTRIBUTE'),
                Mage::helper('smile_elasticsearch')->getCacheLifetime()
            );
        }

        return $this->_mapping;
    }

    /**
     * Return mapping for an attribute.
     *
     * @param Mage_Eav_Model_Attribute $attribute Attribute we want the mapping for.
     *
     * @return array
     */
    protected function _getAttributeMapping($attribute)
    {
        $mapping = array();

        if ($this->_canIndexAttribute($attribute)) {
            $attributeCode = $attribute->getAttributeCode();
            $type = $this->_getAttributeType($attribute);

            if ($type === 'string' && !$attribute->getBackendModel() && $attribute->getFrontendInput() != 'media_image') {
                foreach (Mage::app()->getStores() as $store) {
                    $languageCode = Mage::helper('smile_elasticsearch')->getLanguageCodeByStore($store);
                    $fieldName = $attributeCode . '_' . $languageCode;
                    $mapping[$fieldName] = array('type' => $type, 'analyzer' => 'analyzer_' . $languageCode);

                    if ($attribute->getBackendType() == 'varchar') {
                        $mapping[$fieldName] = array('type' => 'multi_field', 'fields' => array($fieldName => $mapping[$fieldName]));
                        $mapping[$fieldName]['fields']['sortable']  = array('type' => $type, 'analyzer' => 'sortable');
                        $mapping[$fieldName]['fields']['untouched'] = array('type' => $type, 'index' => 'not_analyzed');
                    }
                }
            } else if ($type === 'date') {
                $mapping[$attributeCode] = array(
                    'type' => $type,
                    'format' => implode('||', array(Varien_Date::DATETIME_INTERNAL_FORMAT, Varien_Date::DATE_INTERNAL_FORMAT))
                );
            } else {
                $mapping[$attributeCode] = array('type' => $type);
            }

            if ($attribute->usesSource()) {
                foreach (Mage::app()->getStores() as $store) {
                    $languageCode = Mage::helper('smile_elasticsearch')->getLanguageCodeByStore($store);
                    $fieldName = $attributeCode . '_' . $languageCode;
                    $mapping['options_' . $attributeCode . '_' . $languageCode] = array(
                        'type' => 'string',
                        'analyzer' => 'analyzer_' . $languageCode
                    );
                }
            }
        }

        return $mapping;
    }

    /**
     * Returns attribute type for indexation.
     *
     * @param Mage_Catalog_Model_Resource_Eav_Attribute $attribute Attribute
     *
     * @return string
     */
    protected function _getAttributeType($attribute)
    {
        $type = 'string';
        if ($attribute->getBackendType() == 'int' || $attribute->getFrontendClass() == 'validate-digits') {
            $type = 'integer';
        } elseif ($attribute->getBackendType() == 'decimal') {
            $type = 'double';
        } elseif ($attribute->getSourceModel() == 'eav/entity_attribute_source_boolean') {
            $type = 'boolean';
        } elseif ($attribute->getBackendType() == 'datetime') {
            $type = 'date';
        } elseif ($attribute->usesSource() && $attribute->getSourceModel() === null) {
            $type = 'integer';
        } else if ($attribute->usesSource()) {
            $type = 'string';
        }

        return $type;
    }

    /**
     * Indicates if an attribute can be indexed or not.
     *
     * @param Mage_Eav_Model_Attribute $attribute Attribute
     *
     * @return boolean
     */
    protected function _canIndexAttribute($attribute)
    {
        $canIndex = true;

        if ($attribute->getBackendModel() && !in_array($attribute->getBackendModel(), $this->_authorizedBackendModels)) {
            $canIndex = false;
        }

        return $canIndex;
    }


    /**
     * Rebuild the index (full or diff).
     *
     * @param int|null   $storeId Store id the index should be rebuilt for. If null, all store id will be rebuilt.
     * @param array|null $ids     Ids the index should be rebuilt for. If null, processing a fulll reindex
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Mapping_Abstract
     */
    public function rebuildIndex($storeId = null, $ids = null)
    {
        if (is_null($storeId)) {
            $storeIds = array_keys(Mage::app()->getStores());
            foreach ($storeIds as $storeId) {
                $this->_rebuildStoreIndex($storeId, $ids);
            }
        } else {
            $this->_rebuildStoreIndex($storeId, $ids);
        }

        return $this;
    }

    /**
     * Returns the main entity table.
     *
     * @param string $modelEntity Entity name
     *
     * @return string
     */
    public function getTable($modelEntity)
    {
        return Mage::getSingleton('core/resource')->getTableName($modelEntity);
    }

    /**
     * Return DB connection.
     *
     * @return Varien_Db_Adapter_Interface
     */
    public function getConnection()
    {
        return Mage::getSingleton('core/resource')->getConnection('write');;
    }

    /**
     * Rebuild the index (full or diff).
     *
     * @param int        $storeId   Store id the index should be rebuilt for.
     * @param array|null $entityIds Ids the index should be rebuilt for. If null, processing a fulll reindex
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Mapping_Abstract
     */
    protected function _rebuildStoreIndex($storeId, $entityIds = null)
    {
        $store = Mage::app()->getStore($storeId);
        $websiteId = $store->getWebsiteId();

        $languageCode = Mage::helper('smile_elasticsearch')->getLanguageCodeByStore($store);

        $dynamicFields = array();
        $attributesById = $this->_getAttributesById();

        foreach ($attributesById as $attribute) {
            if ($this->_canIndexAttribute($attribute) && $attribute->getBackendType() != 'static') {
                $dynamicFields[$attribute->getBackendTable()][] = $attribute->getAttributeId();
            }
        }

        $websiteId = Mage::app()->getStore($storeId)->getWebsite()->getId();
        $lastObjectId = 0;

        while (true) {

            $entities = $this->_getSearchableEntities($storeId, $entityIds, $lastObjectId);

            if (!$entities) {
                break;
            }

            $ids = array();

            foreach ($entities as $entityData) {
                $lastObjectId = $entityData['entity_id'];
                $ids[]  = $entityData['entity_id'];
            }

            $entityRelations = $this->_getChildrenIds($ids, $websiteId);
            foreach ($entityRelations as $childrenIds) {
                $ids = array_merge($ids, $childrenIds);
            }

            $entityIndexes    = array();
            $entityAttributes = $this->_getAttributes($storeId, $ids, $dynamicFields);

            foreach ($entities as $entityData) {

                if (!isset($entityAttributes[$entityData['entity_id']])) {
                    continue;
                }

                $entityAttr = array();

                foreach ($entityAttributes[$entityData['entity_id']] as $attributeId => $value) {
                    $attribute = $attributesById[$attributeId];
                    $entityAttr = array_merge(
                        $entityAttr,
                        $this->_getAttributeIndexValues($attribute, $value, $storeId, $languageCode)
                    );

                }

                $entityAttr = array_merge($entityData, $entityAttr);
                $entityAttr['store_id'] = $storeId;
                $entityIndexes[$entityData['entity_id']] = $entityAttr;
            }

            $entityIndexes = $this->_addChildrenData($entityIndexes, $entityAttributes, $entityRelations, $storeId, $languageCode);
            $entityIndexes = $this->_addSuggestField($entityIndexes, $storeId, $languageCode);

            $this->_saveIndexes($storeId, $entityIndexes);
        }

        return $this;
    }

    /**
     * Return the indexed attribute value.
     *
     * @param Mage_Eav_Model_Attribute $attribute    Attribute we want the value for.
     * @param mixed                    $value        Raw value
     * @param int                      $storeId      Store id
     * @param string                   $languageCode Locale code
     *
     * @return mixed.
     */
    protected function _getAttributeIndexValues($attribute, $value, $storeId, $languageCode)
    {
        $attrs = array();

        if ($value && $attribute) {
            $field = $this->_getAttributeFieldName($attribute, $languageCode);
            if ($field) {
                $storedValue = $this->_getAttributeValue($attribute, $value, $storeId);

                if ($storedValue != null && $storedValue != false && $storedValue != '0000-00-00 00:00:00') {
                    $attrs[$field] = $storedValue;
                }

                if ($attribute->usesSource() && $attribute->getSourceModel()) {
                    $field = 'options_' . $attribute->getAttributeCode() . '_' . $languageCode;
                    $value = $this->_getOptionsText($attribute, $value, $storeId);
                    if ($value) {
                        $attrs[$field] = $value;
                    }
                }
            }
        }

        return $attrs;
    }

    /**
     * Load all entity attributes by ids.
     *
     * @return array.
     */
    protected function _getAttributesById()
    {
        $entityType = Mage::getModel('eav/entity_type')->loadByCode($this->_entityType);
        $attributes = Mage::getResourceModel($this->_attributeCollectionModel)
            ->setEntityTypeFilter($entityType->getEntityTypeId());

        $attributesById = array();

        foreach ($attributes as $attribute) {
            if ($this->_canIndexAttribute($attribute) && $attribute->getBackendType() != 'static') {
                $attributesById[$attribute->getAttributeId()] = $attribute;
            }
        }

        return $attributesById;
    }

    /**
     * Append children attributes to parents doc.
     *
     * @param array  $entityIndexes    Final index results
     * @param array  $entityAttributes Attributes values by entity id
     * @param array  $entityRelations  Array of the entities relations
     * @param int    $storeId          Store id
     * @param string $languageCode     Locale
     *
     * @return array
     */
    protected function _addChildrenData($entityIndexes, $entityAttributes, $entityRelations, $storeId, $languageCode)
    {

        $attributesById = $this->_getAttributesById();

        foreach ($entityRelations as $parentId => $childrenIds) {

            $values = $entityIndexes[$parentId];

            foreach ($childrenIds as $childrenId) {
                if (isset($entityAttributes[$childrenId])) {
                    foreach ($entityAttributes[$childrenId] as $attributeId => $value) {
                        if (isset($attributesById[$attributeId]) &&
                            in_array($attributesById[$attributeId]->getFrontendInput(), array('select', 'multiselect'))
                           ) {
                            $attribute = $attributesById[$attributeId];
                            $childrenValues = $this->_getAttributeIndexValues($attribute, $value, $storeId, $languageCode);
                            foreach ($childrenValues as $field => $fieldValue) {
                                $parentValue = array();

                                if (!is_array($fieldValue)) {
                                    $fieldValue = array($fieldValue);
                                }

                                if (isset($values[$field])) {
                                    $parentValue = is_array($values[$field]) ? $values[$field] : array($values[$field]);
                                }
                                $values[$field] = array_unique(array_merge($parentValue, $fieldValue));
                            }
                        }
                    }
                }
            }

            $entityIndexes[$parentId] = $values;
        }

        return $entityIndexes;
    }

    /**
     * Retrieve entities children ids
     *
     * @param array $entityIds Parent entities ids.
     * @param int   $websiteId Current website ids
     *
     * @return array
     */
    protected function _getChildrenIds($entityIds, $websiteId)
    {
        return array();
    }

    /**
     * Save docs to the index
     *
     * @param int   $storeId       Store id
     * @param array $entityIndexes Doc values.
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Mapping_Catalog_Eav_Abstract
     */
    protected function _saveIndexes($storeId, $entityIndexes)
    {
        Mage::helper('catalogsearch')->getEngine()->saveEntityIndexes($storeId, $entityIndexes, $this->_type);
        return $this;
    }

    /**
     * Retrieve values for attributes.
     *
     * @param int   $storeId        Store id.
     * @param array $entityIds      Entities ids.
     * @param array $attributeTypes Attributes to be indexed.
     *
     * @return array
     */
    protected function _getAttributes($storeId, array $entityIds, array $attributeTypes)
    {
        $result  = array();
        $selects = array();
        $websiteId = Mage::app()->getStore($storeId)->getWebsiteId();
        $adapter = $this->getConnection();
        $ifStoreValue = $adapter->getCheckSql('t_store.value_id > 0', 't_store.value', 't_default.value');

        foreach ($attributeTypes as $tableName => $attributeIds) {
            if ($attributeIds) {
                $select = $adapter->select()
                ->from(array('t_default' => $tableName), array('entity_id', 'attribute_id'))
                ->joinLeft(
                    array('t_store' => $tableName),
                    $adapter->quoteInto(
                        't_default.entity_id=t_store.entity_id' .
                        ' AND t_default.attribute_id=t_store.attribute_id' .
                        ' AND t_store.store_id=?',
                        $storeId
                    ),
                    array('value' => new Zend_Db_Expr('COALESCE(t_store.value, t_default.value)'))
                )
                ->where('t_default.store_id=?', 0)
                ->where('t_default.attribute_id IN (?)', $attributeIds)
                ->where('t_default.entity_id IN (?)', $entityIds);

                /**
                 * Add additional external limitation
                */
                $eventName = sprintf('prepare_catalog_%s_index_select', $this->_type);
                Mage::dispatchEvent(
                    $eventName,
                    array(
                        'select'        => $select,
                        'entity_field'  => new Zend_Db_Expr('t_default.entity_id'),
                        'website_field' => $websiteId,
                        'store_field'   => new Zend_Db_Expr('t_store.store_id')
                    )
                );

                $selects[] = $select;
            }
        }

        if ($selects) {
            $select = $adapter->select()->union($selects, Zend_Db_Select::SQL_UNION_ALL);
            $query = $adapter->query($select);
            while ($row = $query->fetch()) {
                $result[$row['entity_id']][$row['attribute_id']] = $row['value'];
            }
        }

        return $result;
    }

    /**
     * Return the indexed attribute value.
     *
     * @param Mage_Eav_Model_Attribute $attribute Attribute we want the value for.
     * @param mixed                    $value     Raw value
     * @param int                      $storeId   Store id
     *
     * @return mixed.
     */
    protected function _getAttributeValue($attribute, $value, $storeId)
    {
        if ($attribute->usesSource()) {
            $inputType = $attribute->getFrontend()->getInputType();
            if ($inputType == 'multiselect') {
                $value = explode(',', $value);
            }
        } else {
            $inputType = $attribute->getFrontend()->getInputType();
            if ($inputType == 'price') {
                $value = Mage::app()->getStore($storeId)->roundPrice($value);
            }
        }

        if (is_string($value)) {
            $value = preg_replace("#\s+#siu", ' ', trim(strip_tags($value)));
        }

        return $value;
    }

    /**
     * Retrieve the field name for an attributes.
     *
     * @param Mage_Eav_Model_Attribute $attribute    Attribute we want the value for.
     * @param string                   $languageCode Language code
     *
     * @return string
     */
    protected function _getAttributeFieldName($attribute, $languageCode)
    {

        $mapping = $this->getMappingProperties()['properties'];
        $fieldName = $attribute->getAttributeCode();

        if (!isset($mapping[$fieldName])) {
            $fieldName =  $fieldName . '_' . $languageCode;
        }

        if (!isset($mapping[$fieldName])) {
            $fieldName = false;
        }

        return $fieldName;
    }

    /**
     * Append suggest data to the index
     *
     * @param array  $entityIndexes Index data
     * @param int    $storeId       Store id
     * @param string $languageCode  Language code
     *
     * @return array
     */
    protected function _addSuggestField($entityIndexes, $storeId, $languageCode)
    {
        $store = Mage::app()->getStore($storeId);
        $languageCode = Mage::helper('smile_elasticsearch')->getLanguageCodeByStore($store);
        $fieldName = Mage::helper('smile_elasticsearch')->getSuggestFieldName($store);
        $inputFields = array();
        foreach ($this->_suggestInputAttributes as $attribute) {
            $field = $this->getFieldName($attribute, $languageCode);
            $inputFields[] = $field;
        }

        $payloadFields = array();
        foreach ($this->_suggestPayloadAttributes as $attribute) {
            $field = $this->getFieldName($attribute, $languageCode, 'filter');
            $payloadFields[] = $field;
        }

        foreach ($entityIndexes as $entityId => $index) {
            $suggest = array('input' => '', 'payload' => array());

            foreach ($inputFields as $field) {
                if (isset($index[$field])) {

                    if (!isset($suggest['output'])) {
                        $suggest['output'] = is_array($index[$field]) ? current($index[$field]) : $index[$field];
                    }

                    if (is_array($index[$field])) {
                        $index[$field] = implode(' ', $index[$field]);
                    }
                    $suggest['input'] = implode(' ', array($suggest['input'], $index[$field]));
                }
            }

            foreach ($payloadFields as $field) {
                if (isset($index[$field])) {
                    if (!isset($suggest['payload'][$field])) {
                        $suggest['payload'][$field] = $index[$field];
                    } else {
                        if (!is_array($index[$field])) {
                            $index[$field] = array($index[$field]);
                        }
                        if (!is_array($suggest['payload'][$field])) {
                            $suggest['payload'][$field] = array($suggest['payload'][$field]);
                        }
                        $suggest['payload'][$field] = array_merge($suggest['payload'][$field], $index[$field]);
                    }
                }
            }

            $suggest['context']['store_id'] = $storeId;
            $inputs = explode(' ', $suggest['input']);
            $suggest['input'] = array_merge(array($suggest['input']), $inputs);
            $suggest['input'] = array_values(array_filter($suggest['input']));

            $suggest = $this->_appendCustomSuggestData($index, $suggest);

            $entityIndexes[$index['entity_id']][$fieldName] = $suggest;
        }

        return $entityIndexes;
    }

    /**
     * Append custom data for an entity
     *
     * @param array $entityData  Data for current entity
     * @param array $suggestData Suggest data for the entity
     *
     * @return array
     */
    protected function _appendCustomSuggestData($entityData, $suggestData)
    {
        return $suggestData;
    }

    /**
     * Return the text value for an atribute using source model.
     *
     * @param Mage_Eav_Model_Attribute $attribute Attribute we want the value for.
     * @param mixed                    $value     Raw value
     * @param int                      $storeId   Store id
     *
     * @return mixed.
     */
    protected function _getOptionsText($attribute, $value, $storeId)
    {
        $attribute->setStoreId($storeId);
        if ($attribute->getSource()) {
            $value = $attribute->getSource()->getIndexOptionText($value);
        }
        return $value;
    }

    /**
     * Retrive a bucket of indexable entities.
     *
     * @param int         $storeId Store id
     * @param string|null $ids     Ids filter
     * @param int         $lastId  First id
     * @param int         $limit   Size of the bucket
     *
     * @return array
     */
    abstract protected function _getSearchableEntities($storeId, $ids = null, $lastId = 0, $limit = 100);
}
