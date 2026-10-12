const fs = require('fs');
const path = require('path');

const replacements = {
  '#6d54c8': '#c86d6d',
  '#684fc0': '#c04f4f',
  '#8066dd': '#dd6666',
  '#f0ecff': '#fff2f2',
  '#f1eefc': '#fceef1'
};

function walk(dir) {
  let results = [];
  const list = fs.readdirSync(dir);
  list.forEach(function(file) {
    file = path.join(dir, file);
    const stat = fs.statSync(file);
    if (stat && stat.isDirectory()) {
      results = results.concat(walk(file));
    } else if (file.endsWith('.vue')) {
      results.push(file);
    }
  });
  return results;
}

const vueFiles = walk('resources/js');

for (const file of vueFiles) {
  let content = fs.readFileSync(file, 'utf8');
  let changed = false;
  
  for (const [oldC, newC] of Object.entries(replacements)) {
    if (content.includes(oldC) || content.includes(oldC.toUpperCase())) {
      content = content.split(oldC).join(newC);
      content = content.split(oldC.toUpperCase()).join(newC);
      changed = true;
    }
  }
  
  if (changed) {
    fs.writeFileSync(file, content);
    console.log('Updated', file);
  }
}
console.log('Done.');
