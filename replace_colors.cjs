const fs = require('fs');

const file = 'resources/css/v0-design.css';
let content = fs.readFileSync(file, 'utf8');

const replacements = {
  // Primary
  '#6d54c8': '#c86d6d',
  '#5e45b6': '#b65e5e',
  
  // Various text and icons (medium purples)
  '#644bc1': '#c16363',
  '#6348c1': '#c15f5f',
  '#7255d1': '#d16b6b',
  '#8069d1': '#d16969',
  '#7358ca': '#ca6868',
  '#7359ce': '#ce6969',
  '#755bd0': '#d06b6b',
  '#7258c8': '#c86868',
  '#7458cb': '#cb6868',
  '#7256c7': '#c76666',
  '#7358c9': '#c96868',
  
  // Light backgrounds
  '#f0eaff': '#fff0f0',
  '#f0ecff': '#fff2f2',
  '#f7f5fd': '#fff7f7',
  '#f7f5fe': '#fff8f8',
  '#f1edff': '#fff3f3',
  '#fbfaff': '#fffcfc',
  
  // Medium light accents
  '#e3dcff': '#ffdcdc',
  '#cfc4f4': '#f4c4c4',
  '#e4def7': '#f7dede',
  
  // Login visual colors
  '#1e1b36': '#361b1b',
  '#302b54': '#542b2b',
  '#a491f2': '#f29191',
  'rgba(109, 84, 200': 'rgba(200, 109, 109'
};

for (const [oldColor, newColor] of Object.entries(replacements)) {
  content = content.split(oldColor).join(newColor);
  // Also handle uppercase if any
  content = content.split(oldColor.toUpperCase()).join(newColor);
}

fs.writeFileSync(file, content);
console.log('Colors replaced successfully!');
