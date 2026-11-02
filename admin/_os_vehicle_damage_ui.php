<?php
if (!function_exists('os_vehicle_damage_editor')) {
    function os_vehicle_damage_editor(array $points, array $settings = array())
    {
        $normalized = array();
        foreach ($points as $p) {
            $photoRef = isset($p['photo_ref']) ? (string)$p['photo_ref'] : (!empty($p['photo_path']) ? 'perm:' . (string)$p['photo_path'] : '');
            $photoUrl = isset($p['photo_url']) ? (string)$p['photo_url'] : (!empty($p['id']) && $photoRef !== '' ? 'os_damage_photo.php?point_id=' . (int)$p['id'] : '');
            $normalized[] = array(
                'x' => isset($p['position_x']) ? (float)$p['position_x'] : (isset($p['x']) ? (float)$p['x'] : 0),
                'y' => isset($p['position_y']) ? (float)$p['position_y'] : (isset($p['y']) ? (float)$p['y'] : 0),
                'type' => isset($p['damage_type']) ? (string)$p['damage_type'] : (isset($p['type']) ? (string)$p['type'] : 'AVARIA'),
                'description' => isset($p['description']) ? (string)$p['description'] : '',
                'photo_ref' => $photoRef,
                'photo_url' => $photoUrl
            );
        }
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $types = os_vehicle_damage_types();
        $typesJson = json_encode($types, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $mapSrc = os_vehicle_damage_asset_web_path($settings);
        $mapAlt = os_vehicle_damage_alt($settings);
        $mapTitle = os_vehicle_damage_title($settings);
        try { $photoToken = bin2hex(random_bytes(16)); } catch (Throwable $e) { $photoToken = md5(uniqid((string)mt_rand(), true)); }
        ?>
        <div class="c12 os-damage-editor">
            <input type="hidden" name="damage_points_json" id="damage_points_json" value="<?php echo os_e($json ?: '[]'); ?>">
            <input type="file" id="damagePhotoInput" accept="image/*" capture="environment" style="display:none">
            <div class="damage-toolbar">
                <div>
                    <label for="damage_type_selector">Tipo da marcação</label>
                    <select id="damage_type_selector">
                        <?php foreach ($types as $code=>$label): ?>
                            <option value="<?php echo os_e($code); ?>"><?php echo os_e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="damage-toolbar-actions">
                    <button type="button" class="btn btn-outline btn-sm" id="damageUndo">Desfazer última</button>
                    <button type="button" class="btn btn-danger btn-sm" id="damageClear">Remover todas</button>
                </div>
            </div>
            <div class="hint damage-hint">Clique sobre o desenho para marcar a avaria ou ponto de inspeção. Na marcação criada, use <b>📷 Foto</b> para abrir a câmera do smartphone e registrar a evidência. A foto é reduzida antes de ser armazenada e será excluída automaticamente 48 horas após a conclusão da O.S.; a marcação e a observação permanecem.</div>
            <div class="damage-layout">
                <div class="damage-map" id="damageMap" aria-label="<?php echo os_e($mapTitle); ?>">
                    <img src="<?php echo os_e($mapSrc); ?>" alt="<?php echo os_e($mapAlt); ?>" draggable="false">
                    <div id="damageMarkers" class="damage-markers"></div>
                </div>
                <div class="damage-list-wrap">
                    <div class="damage-list-title">Avarias marcadas</div>
                    <div id="damageList" class="damage-list"></div>
                </div>
            </div>
        </div>
        <style>
        .os-damage-editor{margin-top:4px}.damage-toolbar{display:flex;gap:12px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;margin-bottom:8px}.damage-toolbar>div:first-child{min-width:220px}.damage-toolbar-actions{display:flex;gap:7px;flex-wrap:wrap}.damage-hint{margin-bottom:10px}.damage-layout{display:grid;grid-template-columns:minmax(320px,1.2fr) minmax(300px,.8fr);gap:16px;align-items:start}.damage-map{position:relative;border:1px solid var(--os-border,#cbd5e1);border-radius:10px;background:#fff;overflow:hidden;cursor:crosshair;user-select:none;display:block;padding:0;width:100%;max-width:560px;justify-self:start}.damage-map img{display:block;width:100%;height:auto;object-fit:contain}.damage-markers{position:absolute;inset:0;pointer-events:none}.damage-marker{position:absolute;transform:translate(-50%,-50%);width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#dc2626;color:#fff;border:2px solid #fff;box-shadow:0 1px 5px rgba(0,0,0,.35);font-weight:800;font-size:12px;pointer-events:auto;cursor:pointer}.damage-marker.has-photo:after{content:'📷';position:absolute;right:-8px;bottom:-8px;font-size:11px;background:#fff;border-radius:50%;padding:1px;box-shadow:0 1px 3px rgba(0,0,0,.25)}.damage-marker:hover{transform:translate(-50%,-50%) scale(1.1)}.damage-list-wrap{border:1px solid var(--os-border,#d8e1e5);border-radius:10px;background:#fff;overflow:hidden}.damage-list-title{font-weight:800;padding:10px 12px;background:#f5f8f9;border-bottom:1px solid var(--os-border,#d8e1e5)}.damage-list{max-height:500px;overflow:auto}.damage-empty{padding:18px 12px;text-align:center;color:#64748b}.damage-row{display:grid;grid-template-columns:34px minmax(0,1fr) 92px auto;gap:8px;padding:9px 10px;border-bottom:1px solid #edf2f4;align-items:start}.damage-row:last-child{border-bottom:0}.damage-number{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#dc2626;color:#fff;font-weight:800}.damage-row-fields{display:grid;gap:6px}.damage-row-fields select,.damage-row-fields input{min-height:34px;padding:6px 8px}.damage-photo-cell{display:grid;gap:5px;justify-items:stretch}.damage-photo-cell .btn{width:100%;padding-left:5px;padding-right:5px}.damage-photo-thumb{display:block;border:1px solid #dbe4e7;border-radius:7px;overflow:hidden;background:#f8fafc;text-decoration:none;color:inherit}.damage-photo-thumb img{display:block;width:100%;height:62px;object-fit:cover}.damage-photo-thumb span{display:block;text-align:center;font-size:10px;padding:2px}.damage-remove{align-self:center}.damage-row.is-focus{background:#fff7ed}.damage-uploading{font-size:10px;color:#475569;text-align:center}@media(min-width:901px){.damage-layout{grid-template-columns:minmax(320px,560px) minmax(300px,1fr)}.damage-map{width:100%;max-width:560px;justify-self:start}}@media(max-width:900px){.damage-layout{grid-template-columns:1fr}.damage-list{max-height:none}}@media(max-width:520px){.damage-row{grid-template-columns:32px minmax(0,1fr) 82px}.damage-remove{grid-column:2/4;width:100%}.damage-photo-thumb img{height:54px}}
        </style>
        <script>
        (function(){
            var map=document.getElementById('damageMap'), markerLayer=document.getElementById('damageMarkers'), list=document.getElementById('damageList'), hidden=document.getElementById('damage_points_json'), typeSelector=document.getElementById('damage_type_selector'), photoInput=document.getElementById('damagePhotoInput');
            if(!map||!markerLayer||!list||!hidden)return;
            var types=<?php echo $typesJson ?: '{}'; ?>;
            var uploadToken=<?php echo json_encode($photoToken); ?>;
            var photoIndex=-1;
            var uploading=0;
            var points=[];
            try{points=JSON.parse(hidden.value||'[]');if(!Array.isArray(points))points=[];}catch(e){points=[];}
            points=points.map(function(p){return {x:Number(p.x||0),y:Number(p.y||0),type:types[p.type]?p.type:'AVARIA',description:String(p.description||''),photo_ref:String(p.photo_ref||''),photo_url:String(p.photo_url||'')};}).filter(function(p){return p.x>=0&&p.x<=100&&p.y>=0&&p.y<=100;});
            function sync(){hidden.value=JSON.stringify(points.map(function(p){return {x:p.x,y:p.y,type:p.type,description:p.description,photo_ref:p.photo_ref||''};}));}
            function esc(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
            function focusRow(index){var rows=list.querySelectorAll('.damage-row');rows.forEach(function(r){r.classList.remove('is-focus');});if(rows[index]){rows[index].classList.add('is-focus');rows[index].scrollIntoView({block:'nearest',behavior:'smooth'});setTimeout(function(){if(rows[index])rows[index].classList.remove('is-focus');},1200);}}
            function render(){
                markerLayer.innerHTML='';list.innerHTML='';
                if(!points.length){list.innerHTML='<div class="damage-empty">Nenhuma avaria marcada.</div>';sync();return;}
                points.forEach(function(p,i){
                    var m=document.createElement('button');m.type='button';m.className='damage-marker'+(p.photo_ref?' has-photo':'');m.style.left=p.x+'%';m.style.top=p.y+'%';m.textContent=String(i+1);m.title=(types[p.type]||p.type)+(p.description?' - '+p.description:'')+(p.photo_ref?' - foto anexada':'');m.addEventListener('click',function(ev){ev.stopPropagation();focusRow(i);});markerLayer.appendChild(m);
                    var row=document.createElement('div');row.className='damage-row';row.dataset.index=i;
                    var options='';Object.keys(types).forEach(function(k){options+='<option value="'+esc(k)+'"'+(p.type===k?' selected':'')+'>'+esc(types[k])+'</option>';});
                    var photoHtml='<button type="button" class="btn btn-outline btn-sm damage-photo-btn">📷 Foto</button>';
                    if(p.photo_url){photoHtml+='<a class="damage-photo-thumb" href="'+esc(p.photo_url)+'" target="_blank" rel="noopener"><img src="'+esc(p.photo_url)+'" alt="Foto da avaria"><span>Ver foto</span></a><button type="button" class="btn btn-secondary btn-sm damage-photo-remove">Remover foto</button>';}
                    row.innerHTML='<div class="damage-number">'+(i+1)+'</div><div class="damage-row-fields"><select class="damage-type">'+options+'</select><input class="damage-description" maxlength="255" placeholder="Observação (opcional), ex.: risco na porta" value="'+esc(p.description)+'"></div><div class="damage-photo-cell">'+photoHtml+'</div><button type="button" class="btn btn-danger btn-sm damage-remove">Remover</button>';
                    row.querySelector('.damage-type').addEventListener('change',function(){points[i].type=this.value;sync();render();focusRow(i);});
                    row.querySelector('.damage-description').addEventListener('input',function(){points[i].description=this.value;sync();m.title=(types[points[i].type]||points[i].type)+(this.value?' - '+this.value:'');});
                    row.querySelector('.damage-photo-btn').addEventListener('click',function(){photoIndex=i;if(photoInput){photoInput.value='';photoInput.click();}});
                    var removePhoto=row.querySelector('.damage-photo-remove');if(removePhoto)removePhoto.addEventListener('click',function(){if(confirm('Remover a foto desta avaria? A marcação e a observação serão mantidas.')){points[i].photo_ref='';points[i].photo_url='';sync();render();focusRow(i);}});
                    row.querySelector('.damage-remove').addEventListener('click',function(){points.splice(i,1);render();});
                    list.appendChild(row);
                });
                sync();
            }
            map.addEventListener('click',function(ev){
                if(points.length>=100){alert('Limite de 100 marcações por O.S.');return;}
                var rect=map.getBoundingClientRect();var x=((ev.clientX-rect.left)/rect.width)*100;var y=((ev.clientY-rect.top)/rect.height)*100;
                x=Math.max(0,Math.min(100,x));y=Math.max(0,Math.min(100,y));
                points.push({x:Number(x.toFixed(4)),y:Number(y.toFixed(4)),type:typeSelector.value||'AVARIA',description:'',photo_ref:'',photo_url:''});render();focusRow(points.length-1);
            });
            if(photoInput){photoInput.addEventListener('change',function(){
                var idx=photoIndex,file=this.files&&this.files[0]?this.files[0]:null;if(idx<0||!points[idx]||!file)return;
                if(file.size>8*1024*1024){alert('A foto deve ter no máximo 8 MB.');return;}
                var row=list.querySelector('.damage-row[data-index="'+idx+'"]');var cell=row?row.querySelector('.damage-photo-cell'):null;if(cell)cell.insertAdjacentHTML('beforeend','<div class="damage-uploading">Enviando...</div>');
                var fd=new FormData();fd.append('photo',file);fd.append('token',uploadToken);uploading++;
                fetch('os_damage_photo_upload.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.text().then(function(t){var j;try{j=JSON.parse(t);}catch(e){throw new Error('Resposta inválida ao enviar a foto.');}if(!r.ok||!j.ok)throw new Error(j.message||'Não foi possível enviar a foto.');return j;});}).then(function(j){if(!points[idx])return;points[idx].photo_ref=j.photo_ref||'';points[idx].photo_url=j.photo_url||'';sync();render();focusRow(idx);}).catch(function(e){alert(e.message||'Falha ao enviar a foto.');render();}).finally(function(){uploading=Math.max(0,uploading-1);});
            });}
            document.addEventListener('submit',function(ev){if(uploading>0&&ev.target&&ev.target.querySelector&&ev.target.querySelector('#damage_points_json')){ev.preventDefault();alert('Aguarde o envio da foto da avaria terminar antes de salvar a O.S.');}},true);
            document.getElementById('damageUndo').addEventListener('click',function(){if(points.length){points.pop();render();}});
            document.getElementById('damageClear').addEventListener('click',function(){if(!points.length)return;if(confirm('Remover todas as marcações de avaria desta O.S.?')){points=[];render();}});
            render();
        })();
        </script>
        <?php
    }
}
