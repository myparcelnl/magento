# Magento Manual

For the manual and more information, please refer to our [Magento Manual] on the [MyParcel Developer Portal].

## Important notice about third-party checkouts

> :warning: Third-Party Checkouts Compatibility.

Our plugin may not be fully compatible with “third-party” checkout solutions provided by plugins, other than those native to Magento itself. Features such as Delivery Options or other functionalities might not work as expected.

We recommend testing the plugin’s functionality in your specific setup before fully implementing it.

[Magento Manual]: https://developer.myparcel.nl/nl/documentatie/13.magento2.html
[MyParcel Developer Portal]: https://developer.myparcel.nl

## Installation

Install using composer require.

```bash
composer require myparcelnl/magento
```

## For developers

Clone this repository in `app/code/MyParcelNL/Magento/` using git and run the following commands in the root directory of your Magento installation:

```bash
composer config repositories.myparcelnl/magento path app/code/MyParcelNL/Magento/
composer require myparcelnl/magento
```

This will install our SDK as a dependency.

You can install the SDK as a git repository as well:
Clone MyParcelNL/Sdk in `app/code/MyParcelNL/Sdk/` using git and run the following commands in the root directory of your Magento installation:

```bash
composer config repositories.myparcelnl/sdk path app/code/MyParcelNL/Sdk/
composer require myparcelnl/sdk
```
