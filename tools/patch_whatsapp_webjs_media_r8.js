'use strict';

const fs = require('fs');
const path = require('path');

const serviceDir = process.argv[2] || '/opt/nexa-whatsapp-service';
const packageRoot = path.join(serviceDir, 'node_modules', 'whatsapp-web.js');
const packageJson = path.join(packageRoot, 'package.json');
const utilsFile = path.join(packageRoot, 'src', 'util', 'Injected', 'Utils.js');

function die(message) {
  console.error(`ERRO: ${message}`);
  process.exit(1);
}

if (!fs.existsSync(packageJson)) die(`whatsapp-web.js não encontrado em ${packageRoot}`);
const pkg = JSON.parse(fs.readFileSync(packageJson, 'utf8'));
if (pkg.version !== '1.34.7') {
  die(`patch R8 homologado somente para whatsapp-web.js 1.34.7. Versão encontrada: ${pkg.version || 'desconhecida'}`);
}
if (!fs.existsSync(utilsFile)) die(`arquivo esperado não encontrado: ${utilsFile}`);

const marker = 'NEXA-R8: remove MediaData private id';
let text = fs.readFileSync(utilsFile, 'utf8');
if (text.includes(marker)) {
  console.log('Patch R8 já aplicado; nenhuma alteração necessária.');
  process.exit(0);
}

// WhatsApp Web passou a expor o MediaData com a propriedade privada enumerable
// __x_id. whatsapp-web.js espalha mediaOptions dentro da mensagem depois de definir
// o MsgKey correto em `id`; com isso, __x_id colide com o ID interno do Msg model e
// pode causar: "Data passed to getter must include an id property ... got undefined".
// Correção conservadora proposta no issue upstream #201922: remover __x_id do objeto
// final antes de inicializar/enviar a mensagem.
const anchor = `        };\n        // Bot's won't reply if canonicalUrl is set (linking)`;
const replacement = `        };\n\n        // ${marker}.\n        // MediaData.__x_id is private metadata and must not overwrite the outgoing Msg identity.\n        if (Object.prototype.hasOwnProperty.call(message, '__x_id')) {\n            delete message.__x_id;\n        }\n\n        // Bot's won't reply if canonicalUrl is set (linking)`;

if (!text.includes(anchor)) {
  die('ponto de inserção do sendMessage não encontrado em Utils.js; nenhum arquivo foi alterado.');
}

const stamp = new Date().toISOString().replace(/[-:TZ.]/g, '').slice(0, 14);
const backup = `${utilsFile}.bak-nexa-r8-${stamp}`;
fs.copyFileSync(utilsFile, backup);
text = text.replace(anchor, replacement);

try {
  new Function(text);
} catch (e) {
  die(`sintaxe inválida após patch: ${e.message}`);
}

fs.writeFileSync(utilsFile, text, 'utf8');
console.log(`PATCH: src/util/Injected/Utils.js (backup: ${backup})`);
console.log('Patch R8 aplicado: MediaData.__x_id removido antes da criação/envio do Msg.');
