const mainConfig = require('@myparcel-dev/semantic-release-config');
const { addExecPlugin, addGitHubPlugin, addGitPlugin } = require(
  '@myparcel-dev/semantic-release-config/src/plugins',
);
const { gitPluginDefaults } = require('@myparcel-dev/semantic-release-config/src/plugins/addGitPlugin');

module.exports = {
  ...mainConfig,
  extends: '@myparcel-dev/semantic-release-config',
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
