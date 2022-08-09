const mainConfig = require('@myparcel/semantic-release-config');
const { addExecPlugin, addGitHubPlugin, addGitPlugin } = require(
  '@myparcel/semantic-release-config/src/plugins',
);
const { gitPluginDefaults } = require('@myparcel/semantic-release-config/src/plugins/addGitPlugin');

module.exports = {
  ...mainConfig,
  extends: '@myparcel/semantic-release-config',
  plugins: [
    ...mainConfig.plugins,
    addGitHubPlugin(),
    addExecPlugin({
      prepareCmd: 'node ./private/updateVersion.js ${nextRelease.version}',
    }),
    addGitPlugin({
      assets: [
        ...gitPluginDefaults.assets,
        'etc/module.xml',
      ],
    }),
  ],
};
