(function (wp) {
  'use strict';
  if (!wp || !wp.blocks || !wp.element || !wp.components || !wp.blockEditor || !wp.apiFetch) {return;}
  var el=wp.element.createElement;
  var TextControl=wp.components.TextControl;
  var SelectControl=wp.components.SelectControl;
  var Button=wp.components.Button;
  var PanelBody=wp.components.PanelBody;
  var InspectorControls=wp.blockEditor.InspectorControls;
  var useState=wp.element.useState;
  var useEffect=wp.element.useEffect;
  var ServerSideRender=wp.serverSideRender || wp.components.ServerSideRender;
  wp.blocks.registerBlockType('azlinkpro/amazon-image',{
    title:'Amazon Product Image (AzlinkPRO)',
    description:'Display one clickable product image selected from Amazon Creators API.',
    icon:'format-image',category:'widgets',
    attributes:{id:{type:'number',default:0}},
    supports:{html:false},
    edit:function(props){
      var id=props.attributes.id || 0;
      var state=useState([]), choices=state[0], setChoices=state[1];
      var loadingState=useState(false), loading=loadingState[0], setLoading=loadingState[1];
      function refresh(){
        setLoading(true);
        wp.apiFetch({path:'/wp/v2/azp_product_images?per_page=100&status=publish&_fields=id,title'})
          .then(function (list){
            setChoices(Array.isArray(list)?list.map(function(post){return {label:(post.title&&post.title.rendered?post.title.rendered:'Image')+' (#'+post.id+')',value:String(post.id)};}):[]);
          })
          .catch(function(){setChoices([]);})
          .finally(function(){setLoading(false);});
      }
      useEffect(function(){refresh();},[]);
      var options=[{label:'Select a saved Amazon image…',value:'0'}].concat(choices);
      if (id && !options.some(function(opt){return opt.value===String(id);})) {
        options.push({label:'Saved image #'+id,value:String(id)});
      }
      var controls=el('div',{},
        el(SelectControl,{
          label:'Saved Amazon Product Image',
          value:String(id),options:options,
          onChange:function(v){var n=parseInt(v,10);props.setAttributes({id:Number.isFinite(n)&&n>0?n:0});}
        }),
        el(Button,{variant:'secondary',isBusy:loading,onClick:refresh},'Refresh image list'),
        el('p',{},el('a',{href:'post-new.php?post_type=azp_product_image',target:'_blank',rel:'noopener noreferrer'},'Create a new Amazon Product Image')),
        el(TextControl,{
          label:'Or enter the image ID',
          type:'number',min:1,value:id||'',
          onChange:function(v){var n=parseInt(v,10);props.setAttributes({id:Number.isFinite(n)&&n>0?n:0});}
        })
      );
      return el('div',{className:'azp-image-block-editor'},
        el(InspectorControls,{},el(PanelBody,{title:'Amazon Image Settings',initialOpen:true},controls)),
        id && ServerSideRender ? el(ServerSideRender,{block:'azlinkpro/amazon-image',attributes:{id:id}}) :
          el('p',{},'Choose a saved Amazon product image using the block settings sidebar.')
      );
    },save:function(){return null;}
  });
})(window.wp);
