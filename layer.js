


var layer = function() {
  cache.apply(this, arguments);
  if (this.get_class_names_()[0] === 'layer') {
    layer.layers.push(this);
    if (this.get_previous_layer()) {
      this.state = rocket.clone(this.get_previous_layer().state);
    }
  } else {
    this.state = this.get_top_layer().state;
    this.cache = this.get_top_layer().cache;
  }
};
rocket.inherits(layer, cache);


layer.layers = [];


layer.prototype.get_previous_layer = function() {
  return layer.layers[layer.layers.length - 2];
};


layer.prototype.get_top_layer = function() {
  return layer.layers[layer.layers.length - 1];
};


layer.prototype.get_layers = function() {
  return layer.layers;
};


layer.prototype.get_class_names_ = function() {
  return this.constructor.prototype.class_names_ = this.class_names_ = this.class_names_ || this.get_class_name_recursive_({
    'layer': layer,
    'component': component
  });
};


layer.prototype.get_class_name_recursive_ = function(parent, opt_prefix) {
  for (var i in parent) {
    if (
      (parent[i]) &&
      (parent[i].prototype) &&
      (this instanceof parent[i])
    ) {
      var name = opt_prefix ? rocket.clone(opt_prefix) : [];
      name.push(i);
      if (parent[i] === this.constructor) {
        return name;
      } else {
        if (name = this.get_class_name_recursive_(parent[i], name)) {
          return name;
        }
      }
    }
  }
};


layer.prototype.layer_previous_container_;


layer.prototype.render = function(opt_parent, opt_before) {
  if (this.get_class_names_()[0] === 'layer') {
    rocket.EventTarget.removeAllEventListeners();
  }
  var container = rocket.createElement('div').addClass(this.get_class_names_());
  this.decorate(container);
  if (container.innerHTML()) {
    if (
      (this.layer_previous_container_) &&
      (this.layer_previous_container_.parentNode().length)
    ) {
      this.layer_previous_container_.parentNode().replaceChild(container, this.layer_previous_container_);
    } else {
      (opt_parent || rocket.$('body').innerHTML('')).insertBefore(container, opt_before);
    }
    this.layer_previous_container_ = container;
    this.dispatchEvent('render');
    this.envoy.dispatchEvent('render');
  }
};


layer.prototype.render_remove = function() {
  if (this.layer_previous_container_.parentNode().length) {
    this.layer_previous_container_.parentNode().removeChild(this.layer_previous_container_);
  }
};


layer.prototype.decorate = function() {};


layer.prototype.render_previous = function(opt_cancel) {
  if (opt_cancel) {
    layer.layers.pop();
    this.get_top_layer().render();
  } else {
    this.get_previous_layer().state = this.state;
    rocket.EventTarget.removeAllEventListeners();
    this.flush(function() {
      layer.layers.pop();
      this.get_top_layer().render();
    });
  }
};


layer.prototype.render_previous_without_state = function() {
  rocket.EventTarget.removeAllEventListeners();
  this.flush(function() {
    layer.layers.pop();
    this.get_top_layer().render();
  });
};


layer.prototype.render_previous_without_cache = function() {
  this.get_previous_layer().state = this.state;
  layer.layers.pop();
  this.get_top_layer().render();
};


layer.prototype.render_current = function(opt_cancel) {
  if (opt_cancel) {
    this.get_top_layer().cache = {};
    this.get_top_layer().state = this.get_previous_layer() ?
      rocket.clone(this.get_previous_layer().state) :
      {};
  }
  this.get_top_layer().render();
};


layer.prototype.layer_propagate_cache_ = function(layers) {
  for (var i = 0; layer.layers[i]; ++i) {
    this.cache_propagate(layer.layers[i].cache, this.cache);
  }
};


layer.prototype.render_clear = function(opt_cancel) {
  if (opt_cancel) {
    this.get_top_layer().state = {};
    this.get_top_layer().cache = {};
  } else {
    this.layer_propagate_cache_(layer.layers);
  }
  layer.layers = [this.get_top_layer()];
  this.get_top_layer().render();
};


layer.prototype.render_replace = function(opt_cancel, opt_replacements) {
  var replacements = opt_replacements || 1;
  var layers = layer.layers.splice(layer.layers.length - 1 - replacements, replacements);
  if (opt_cancel) {
    this.get_top_layer().state = {};
    this.get_top_layer().cache = {};
  } else {
    this.layer_propagate_cache_(layers)
  }
  this.get_top_layer().render();
};
