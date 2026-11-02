'use strict';

const fs = require('fs');
const path = require('path');

const serviceDir = process.argv[2] || '/opt/nexa-whatsapp-service';
const packageRoot = path.join(serviceDir, 'node_modules', 'whatsapp-web.js');
const packageJson = path.join(packageRoot, 'package.json');
const utilsFile = path.join(packageRoot, 'src', 'util', 'Injected', 'Utils.js');
const baseFile = path.join(packageRoot, 'src', 'structures', 'Base.js');

function die(message) {
  console.error(`ERRO: ${message}`);
  process.exit(1);
}

if (!fs.existsSync(packageJson)) die(`whatsapp-web.js não encontrado em ${packageRoot}`);
const pkg = JSON.parse(fs.readFileSync(packageJson, 'utf8'));
if (pkg.version !== '1.34.7') {
  die(`patch R8.1 homologado somente para whatsapp-web.js 1.34.7. Versão encontrada: ${pkg.version || 'desconhecida'}`);
}
if (!fs.existsSync(utilsFile)) die(`arquivo esperado não encontrado: ${utilsFile}`);
if (!fs.existsSync(baseFile)) die(`arquivo esperado não encontrado: ${baseFile}`);

// R8.1 é deliberadamente incremental: não reaplica a R7.
// Isso evita depender novamente do texto original de getChat em uma instalação já corrigida.
const baseText = fs.readFileSync(baseFile, 'utf8');
if (!baseText.includes('static _normalizeId(id)')) {
  die('compatibilidade R7 (_serialized/$1) não foi detectada. Instale a R7 antes da R8.1.');
}

const marker = 'NEXA-R8: remove MediaData private id';
let text = fs.readFileSync(utilsFile, 'utf8');
if (text.includes(marker)) {
  console.log('Patch R8/R8.1 já aplicado; nenhuma alteração necessária.');
  process.exit(0);
}

if (!text.includes('window.WWebJS.sendMessage = async')) {
  die('função window.WWebJS.sendMessage não encontrada em Utils.js.');
}

// O whatsapp-web.js 1.34.7 constrói `message` espalhando mediaOptions/toJSON.
// O WhatsApp Web atual pode expor MediaData.__x_id como propriedade enumerável;
// ela não deve alcançar o modelo Msg porque conflita com a identidade interna.
// Inserimos a remoção imediatamente após a montagem do objeto `message`.
const re = /(\.\.\.extraOptions,\r?\n\s*};)(\r?\n\s*\/\/ Bot's won't reply if canonicalUrl is set \(linking\))/;
const match = text.match(re);
if (!match) {
  die('ponto de inserção após ...extraOptions não encontrado em Utils.js; nenhum arquivo foi alterado.');
}

const insertion = `${match[1]}\n\n        // ${marker}.\n        // Private MediaData metadata must not overwrite the outgoing Msg identity.\n        if (Object.prototype.hasOwnProperty.call(message, '__x_id')) {\n            delete message.__x_id;\n        }${match[2]}`;

const stamp = new Date().toISOString().replace(/[-:TZ.]/g, '').slice(0, 14);
const backup = `${utilsFile}.bak-nexa-r8_1-${stamp}`;
fs.copyFileSync(utilsFile, backup);
const patched = text.replace(re, insertion);

try {
  new Function(patched);
} catch (e) {
  die(`sintaxe inválida após patch: ${e.message}`);
}

fs.writeFileSync(utilsFile, patched, 'utf8');
console.log(`PATCH: src/util/Injected/Utils.js (backup: ${backup})`);
console.log('Patch R8.1 aplicado: MediaData.__x_id removido antes do envio; R7 existente foi preservada.');
