<?php
/**
 * This class contain all methods to check the type of package
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl/magento
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 2.0.0
 */

namespace MyParcelBE\Magento\Model\Sales;

use MyParcelBE\Magento\Helper\Data;

class Package extends Data implements PackageInterface
{
    const PACKAGE_TYPE_NORMAL        = 1;
    const PACKAGE_TYPE_MAILBOX       = 2;
    const PACKAGE_TYPE_LETTER        = 3;
    const PACKAGE_TYPE_DIGITAL_STAMP = 4;

    /**
     * @var int
     */
    private $weight = 0;

    /**
     * @var float
     */
    private $maxMailBoxWeight = 0;

    /**
     * @var float
     */
    private $maxDigitalStampWeight = 0;

    /**
     * @var float
     */
    private $maxPackageSmallWeight = 0;

    /**
     * @var bool
     */
    private $mailboxActive = false;

    /**
     * @var bool
     */
    private $packageSmallActive = false;

    /**
     * @var bool
     */
    private $pickupMailboxActive = false;

    /**
     * @var bool
     */
    private $digitalStampActive = false;

    /**
     * @var float
     */
    private $mailboxPercentage = 0.0;

    /**
     * @var bool
     */
    private $allProductsFit = true;

    /**
     * @var string
     */
    private $currentCountry = 'NL';

    /**
     * @var int
     */
    private $packageType = null;

    /**
     * @return float
     */
    public function getWeight(): float
    {
        return $this->weight;
    }

    /**
     * @param float $weight
     */
    public function setWeight(float $weight): void
    {
        $this->weight = $weight;
    }

    /**
     * @param float $weight
     */
    public function addWeight(float $weight): void
    {
        $this->weight += $weight;
    }

    /**
     * @return float
     */
    public function getMaxMailboxWeight(): float
    {
        return $this->maxMailBoxWeight;
    }

    /**
     * @param float $max_weight
     */
    public function setMaxMailboxWeight(float $max_weight): void
    {
        $this->maxMailBoxWeight = $max_weight;
    }

    /**
     * @return float
     */
    public function getMaxDigitalStampWeight(): float
    {
        return $this->maxDigitalStampWeight;
    }

    /**
     * @param float $maxWeight
     */
    public function setMaxDigitalStampWeight(float $maxWeight): void
    {
        $this->maxDigitalStampWeight = $maxWeight;
    }

    /**
     * @return bool
     */
    public function isMailboxActive(): bool
    {
        return $this->mailboxActive;
    }

    /**
     * @param bool $mailboxActive
     */
    public function setMailboxActive(bool $mailboxActive): void
    {
        $this->mailboxActive = $mailboxActive;
    }

    /**
     * @return bool
     */
    public function isPackageSmallActive(): bool
    {
        return $this->packageSmallActive;
    }

    /**
     * @param  bool $packageSmallActive
     *
     * @return void
     */
    public function setPackageSmallActive(bool $packageSmallActive): void
    {
        $this->packageSmallActive = $packageSmallActive;
    }

    /**
     * @param  bool $isActive
     *
     * @return void
     */
    public function setPickupMailboxActive(bool $isActive): void
    {
        $this->pickupMailboxActive = $isActive;
    }

    /**
     * @return bool
     */
    public function isPickupMailboxActive(): bool
    {
        return $this->pickupMailboxActive;
    }

    /**
     * @param  float $percentage
     *
     * @return void
     */
    public function setMailboxPercentage(float $percentage): void
    {
        $this->mailboxPercentage = $percentage;
    }

    /**
     * @return float
     */
    public function getMailboxPercentage(): float
    {
        return $this->mailboxPercentage;
    }

    /**
     * @return bool
     */
    public function isDigitalStampActive(): bool
    {
        return $this->digitalStampActive;
    }

    /**
     * @param bool $digitalStampActive
     */
    public function setDigitalStampActive(bool $digitalStampActive): void
    {
        $this->digitalStampActive = $digitalStampActive;
    }

    public function getMaxPackageSmallWeight(): float
    {
        return $this->maxPackageSmallWeight;
    }

    /**
     * @param  float $maxWeight
     *
     * @return void
     */
    protected function setMaxPackageSmallWeight(float $maxWeight): void
    {
        $this->maxPackageSmallWeight = $maxWeight;
    }

    /**
     * @param bool $allProductsFit
     * @deprecated fit in what? use PackageRepository->selectPackageType() to get the relevant package type
     */
    public function setAllProductsFit(bool $allProductsFit): void
    {
        if ($allProductsFit === false) {
            $this->allProductsFit = $allProductsFit;
        }
    }

    /**
     * @return int
     */
    public function getPackageType(): int
    {
        if (! isset($this->packageType)) {
            throw new \RuntimeException('Use setPackageType() before you can getPackageType()');
        }
        return $this->packageType;
    }

    /**
     * @param int $packageType
     */
    public function setPackageType(int $packageType): void
    {
        $this->packageType = $packageType;
    }

    /**
     * @return string
     */
    public function getCurrentCountry(): string
    {
        return $this->currentCountry;
    }

    /**
     * @param string|null $currentCountry
     */
    public function setCurrentCountry(?string $currentCountry): void
    {
        if ($currentCountry === null) {
            return;
        }

        $this->currentCountry = $currentCountry;
    }
}
