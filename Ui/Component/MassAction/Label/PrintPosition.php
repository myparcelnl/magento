<?php
/**
 * Short_description
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2016 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcel\Magento\Ui\Component\MassAction\Label;

use Magento\Framework\UrlInterface;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory;
use Zend\Stdlib\JsonSerializable;

/**
 * Class Options
 */
class PrintPosition implements JsonSerializable
{
    /**
     * @var array
     */
    protected $options;

    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * Additional options params
     *
     * @var array
     */
    protected $data;

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * Base URL for subactions
     *
     * @var string
     */
    protected $urlPath;

    /**
     * Param name for subactions
     *
     * @var string
     */
    protected $paramName;

    /**
     * Additional params for subactions
     *
     * @var array
     */
    protected $additionalData = [];

    /**
     * Constructor
     *
     * @param CollectionFactory $collectionFactory
     * @param UrlInterface $urlBuilder
     * @param array $data
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        UrlInterface $urlBuilder,
        array $data = []
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->data = $data;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * Get action options
     *
     * @return array
     */
    public function jsonSerialize()
    {
        $i=0;
        if ($this->options === null) {
            // get the massaction data from the database table
            $badgeColl = $this->collectionFactory->create()->addFieldToFilter('status',['eq'=>1]);

            if(!count($badgeColl)){
                return $this->options;
            }
            //make a array of massaction
            foreach ($badgeColl as $key => $badge) {
                $options[$i]['value']=$badge->getEntityId();
                $options[$i]['label']=$badge->getBadgeName();
                $i++;
            }
            $this->prepareData();
            foreach ($options as $optionCode) {
                $this->options[$optionCode['value']] = [
                    'type' => 'badge_' . $optionCode['value'],
                    'label' => $optionCode['label'],
                ];

                if ($this->urlPath && $this->paramName) {
                    $this->options[$optionCode['value']]['url'] = $this->urlBuilder->getUrl(
                        $this->urlPath,
                        [$this->paramName => $optionCode['value']]
                    );
                }

                $this->options[$optionCode['value']] = array_merge_recursive(
                    $this->options[$optionCode['value']],
                    $this->additionalData
                );
            }

            // return the massaction data
            $this->options = array_values($this->options);
        }
        return $this->options;
    }

    /**
     * Prepare addition data for subactions
     *
     * @return void
     */
    protected function prepareData()
    {

        foreach ($this->data as $key => $value) {
            switch ($key) {
                case 'urlPath':
                    $this->urlPath = $value;
                    break;
                case 'paramName':
                    $this->paramName = $value;
                    break;
                default:
                    $this->additionalData[$key] = $value;
                    break;
            }
        }
    }
}