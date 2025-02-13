<?php
/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace MyParcelNL\Magento\Model\Order\Email\Container;

use Magento\Sales\Model\Order\Email\Container\Container;
use Magento\Sales\Model\Order\Email\Container\IdentityInterface;

class TrackIdentity extends Container implements IdentityInterface
{
    /**
     * Configuration paths
     */
    const XML_PATH_EMAIL_COPY_METHOD             = 'sales_email/shipment/copy_method';
    const XML_PATH_EMAIL_COPY_TO                 = 'sales_email/shipment/copy_to';
    const XML_PATH_EMAIL_IDENTITY                = 'sales_email/track/identity';
    const XML_PATH_EMAIL_ENABLED                 = 'sales_email/track/enabled';
    const XML_PATH_EMAIL_GUEST_TEMPLATE          = 'sales_email/track/guest_template';
    const XML_PATH_EMAIL_TEMPLATE                = 'sales_email/track/template';
    const XML_PATH_EMAIL_SHIPMENT_GUEST_TEMPLATE = 'sales_email/shipment/guest_template';
    const XML_PATH_EMAIL_SHIPMENT_TEMPLATE       = 'sales_email/shipment/template';

    /**
     * Is email enabled
     *
     * @return bool
     */
    public function isEnabled()
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_EMAIL_ENABLED,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $this->getStore()->getStoreId()
        );
    }

    /**
     * Return list of copy_to emails
     *
     * @return array|bool
     */
    public function getEmailCopyTo()
    {
        $data = $this->getConfigValue(self::XML_PATH_EMAIL_COPY_TO, $this->getStore()->getStoreId());
        if (! empty($data)) {
            return explode(',', $data);
        }
        return false;
    }

    /**
     * Return email copy method
     *
     * @return mixed
     */
    public function getCopyMethod()
    {
        return $this->getConfigValue(self::XML_PATH_EMAIL_COPY_METHOD, $this->getStore()->getStoreId());
    }

    /**
     * Return guest template id
     *
     * @return mixed
     */
    public function getGuestTemplateId()
    {
        $templateId = $this->getConfigValue(self::XML_PATH_EMAIL_GUEST_TEMPLATE, $this->getStore()->getStoreId());
        if ($templateId == null) {
            $templateId = $this->getConfigValue(self::XML_PATH_EMAIL_SHIPMENT_GUEST_TEMPLATE, $this->getStore()->getStoreId());
        }

        return $templateId;
    }

    /**
     * Return template id
     *
     * @return mixed
     */
    public function getTemplateId()
    {
        $templateId = $this->getConfigValue(self::XML_PATH_EMAIL_TEMPLATE, $this->getStore()->getStoreId());
        if ($templateId == null) {
            $templateId = $this->getConfigValue(self::XML_PATH_EMAIL_SHIPMENT_TEMPLATE, $this->getStore()->getStoreId());
        }

        return $templateId;
    }

    /**
     * Return email identity
     *
     * @return mixed
     */
    public function getEmailIdentity()
    {
        return $this->getConfigValue(self::XML_PATH_EMAIL_IDENTITY, $this->getStore()->getStoreId());
    }
}
