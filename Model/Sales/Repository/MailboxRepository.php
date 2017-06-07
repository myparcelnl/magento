<?php
/**
 * This class contain all functions to check type of package
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl/magento
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 2.0.0
 */

namespace Model\Sales\Repository;


use MyParcelNL\Magento\Sales\Package;

class PackageRepository extends Package
{

    /**
     * Get package type
     *
     * If package type is not set, calculate package type
     *
     * @return int 1|2|3
     */
    public function getPackageType(): int
    {
        // return type if type is set
        if ($this->getPackageType() !== null) {
            return parent::getPackageType();
        }

        // Set Mailbox if possible
        if ($this->getCurrentCountry() == 'NL' &&
            $this->mailboxIsActive() === true &&
            $this->fitInMailbox() === true
        ) {
            $this->setPackageType(self::PACKAGE_TYPE_MAILBOX);
        }

        return parent::getPackageType();
    }

    /**
     * @return bool
     * @todo check if products fit in mailbox
     */
    public function fitInMailbox(): bool
    {
        return true;
    }
}