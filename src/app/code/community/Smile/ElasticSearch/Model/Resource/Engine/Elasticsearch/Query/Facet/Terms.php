<?php
/**
 * ElaticSearch terms facet model.
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
class Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Query_Facet_Terms
    extends Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Query_Facet_Abstract
{
    const SORT_ORDER_COUNT = 'count';
    const SORT_ORDER_TERM  = 'term';

    /**
     * @var array
     */
    protected $_options = array(
        'size'  => 10,
        'order' => 'count'
    );

    /**
     * Transform the facet into an ES syntax compliant array.
     *
     * @return array
     */
    protected function _getFacetQuery()
    {
        return array('terms' => $this->_options);
    }

    /**
     * Parse the response to extract facet items.
     *
     * @param array $response Query response data.
     *
     * @return array
     */
    public function getItems($response)
    {
        $result = array();

        if (isset($response['terms'])) {
            foreach ($response['terms'] as $value) {
                $result[$value['term']] = $value['count'];
            }
        }
        return $result;
    }
}