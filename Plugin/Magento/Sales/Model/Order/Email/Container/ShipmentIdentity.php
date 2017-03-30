<?php


namespace MyParcelNL\Magento\Plugin\Magento\Sales\Model\Order\Email\Container;

use Magento\Framework\App\ObjectManager;

class ShipmentIdentity
{

    public function afterIsEnabled() {
        $objectManager =  ObjectManager::getInstance();

        /**
         * @var \Magento\Framework\App\Request\Http $request
         */
        $request = $objectManager->get('\Magento\Framework\App\Request\Http');
        if ($request->getParam('myparcel_track_email'))
            return false;
    }
}
