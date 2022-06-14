const fs = require('fs');
const path = require('path');

const [, , version] = process.argv;

const rootDir = path.resolve(__dirname, '..');
const parsedVersion = version.replace(/^v/, '');

[
  'composer.json',
  'package.json',
  'etc/module.xml',
].forEach((file) => {
  const filePath = path.resolve(rootDir, file);
  const relativeFilePath = path.relative(rootDir, filePath);
  const extension = file.split('.').pop();
  let contentsAsString;
  let oldVersion;

  switch (extension) {
    case 'json':
      const contents = require(filePath);

      oldVersion = contents.version;
      contents.version = parsedVersion;

      contentsAsString = JSON.stringify(contents, null, 2);
      break;
    case 'xml':
      contentsAsString = fs.readFileSync(filePath).toString('utf-8');

      const versionRegExp = /setup_version="(.+?)"/;
      oldVersion = contentsAsString.match(versionRegExp)[1];
      contentsAsString = contentsAsString.replace(`setup_version="${oldVersion}"`, `setup_version="${parsedVersion}"`);
      break;
  }

  fs.writeFileSync(filePath, contentsAsString.trim() + '\n');
  console.log(`Changed version from \u{1b}[33m${oldVersion}\u{1b}[0m to \u{1b}[32m${parsedVersion}\u{1b}[0m in ${relativeFilePath}`);
});
