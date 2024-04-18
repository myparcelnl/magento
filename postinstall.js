/* eslint-disable no-console,max-len */
const fs = require('fs');
const path = require('path');
const {execSync} = require('child_process');
const vendor = path.resolve(__dirname, 'view/frontend/web/js/vendor');
const nodeModules = path.resolve(__dirname, 'node_modules');

const copy = [
  [
    `${nodeModules}/object-path/index.js`,
    `${vendor}/object-path.js`,
  ],
];

/**
 * Strip off the absolute part of the given path and remove the first slash (/).
 *
 * @param {String} fileName - Filename to trim.
 *
 * @returns {String}
 */
function basePath(fileName) {
  return fileName.replace(path.resolve(__dirname), '').replace('/', '');
}

fs.readdirSync(vendor).forEach((file) => {
  execSync(`rm -f ${vendor}/${file}`);
});

console.log(`\x1b[32mCleared ${basePath(vendor)} folder.\x1b[0m`);

copy.forEach(([source, dest]) => {
  try {
    execSync(`cp -f ${source} ${dest}`);
    console.log(`\x1b[32mSuccessfully copied\x1b[33m "${basePath(source)}"\x1b[32m to \x1b[33m"${basePath(dest)}"\x1b[0m`);
  } catch (e) {
    console.error(`Failed to copy "${basePath(source)}" to "${basePath(dest)}"`);
    console.error(e);
  }
});
